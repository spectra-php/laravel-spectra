<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Spectra\Facades\Spectra;
use Spectra\Support\Tracking\ProviderErrorException;
use Workbench\App\Models\User;

/**
 * Demo data for the dashboard: a month of realistic traffic, recorded through
 * the real tracking pipeline (contexts, cost calculation, persister) so the
 * numbers on screen come from the same code paths as production.
 */
class DatabaseSeeder extends Seeder
{
    /**
     * provider, model, model type, endpoint, weight, prompt range, completion range
     *
     * @var list<array{0: string, 1: string, 2: string, 3: string, 4: int, 5: array{int, int}, 6: array{int, int}}>
     */
    protected array $mix = [
        ['openai', 'gpt-5.5', 'text', '/v1/responses', 18, [400, 12_000], [80, 2_400]],
        ['openai', 'gpt-5.4-mini', 'text', '/v1/chat/completions', 22, [200, 4_000], [40, 900]],
        ['openai', 'text-embedding-3-small', 'embedding', '/v1/embeddings', 14, [50, 2_000], [0, 0]],
        ['anthropic', 'claude-sonnet-4-6', 'text', '/v1/messages', 16, [1_000, 40_000], [200, 3_000]],
        ['anthropic', 'claude-haiku-4-5', 'text', '/v1/messages', 10, [300, 6_000], [50, 800]],
        ['anthropic', 'claude-opus-4-8', 'text', '/v1/messages', 4, [2_000, 60_000], [400, 6_000]],
        ['google', 'gemini-2.5-flash', 'text', '/v1beta/models/gemini-2.5-flash:generateContent', 9, [300, 8_000], [100, 1_500]],
        ['google', 'gemini-2.5-pro', 'text', '/v1beta/models/gemini-2.5-pro:generateContent', 3, [1_000, 30_000], [300, 4_000]],
        ['mistral', 'mistral-large-latest', 'text', '/v1/chat/completions', 3, [200, 3_000], [50, 700]],
        ['xai', 'grok-4.3', 'text', '/v1/chat/completions', 3, [300, 5_000], [80, 1_200]],
        ['groq', 'whisper-large-v3-turbo', 'stt', '/openai/v1/audio/transcriptions', 2, [0, 0], [0, 0]],
        ['openai', 'gpt-image-1.5', 'image', '/v1/images/generations', 2, [40, 200], [4_000, 6_000]],
        ['openrouter', 'anthropic/claude-sonnet-4.6', 'text', '/api/v1/chat/completions', 5, [500, 20_000], [100, 2_000]],
        ['openrouter', 'deepseek/deepseek-v4-flash', 'text', '/api/v1/chat/completions', 3, [300, 10_000], [80, 1_500]],
        ['groq', 'llama-3.3-70b-versatile', 'text', '/openai/v1/chat/completions', 4, [200, 4_000], [50, 900]],
        ['replicate', 'black-forest-labs/flux-1.1-pro', 'image', '/v1/models/black-forest-labs/flux-1.1-pro/predictions', 2, [0, 0], [0, 0]],
        ['openai', 'tts-1', 'tts', '/v1/audio/speech', 3, [0, 0], [0, 0]],
        ['elevenlabs', 'eleven_flash_v2_5', 'tts', '/v1/text-to-speech/21m00Tcm4TlvDq8rNA0T', 2, [0, 0], [0, 0]],
        ['google', 'veo-3.1-fast-generate-001', 'video', '/v1beta/models/veo-3.1-fast-generate-001:predictLongRunning', 1, [0, 0], [0, 0]],
    ];

    /** @var list<string> */
    protected array $tags = ['support-bot', 'search', 'summaries', 'agent', 'onboarding'];

    public function run(): void
    {
        $users = collect(['Ava Chen', 'Noah Patel', 'Mia Rossi', 'Liam Novak', 'Zoe Martin', 'Omar Haddad', 'Ines Silva', 'Jonas Berg'])
            ->map(fn (string $name, int $i) => User::query()->create([
                'name' => $name,
                'email' => 'user'.($i + 1).'@example.test',
                'password' => bcrypt('password'),
            ]));

        mt_srand(42);
        $weights = array_column($this->mix, 4);

        // Each request freezes the clock at its own time, so remember the real time first.
        $realNow = Carbon::now();
        $today = $realNow->copy()->startOfDay();

        for ($day = 29; $day >= 0; $day--) {
            $date = $today->copy()->subDays($day);
            $count = (int) round((110 + 45 * sin($day / 3)) * ($date->isWeekend() ? 0.6 : 1.0));

            for ($i = 0; $i < $count;) {
                $at = $date->copy()->addSeconds(mt_rand(7 * 3600, 22 * 3600));

                // One unit of work (a request or a job) makes 1-5 AI calls in a row,
                // so they share a trace, the way they do in a real app.
                Spectra::newTrace();
                $user = $users->random();
                $source = mt_rand(0, 3) === 0 ? 'queue' : 'web';
                $steps = [1, 1, 1, 1, 1, 2, 2, 3, 4, 5][mt_rand(0, 9)];

                for ($step = 0; $step < $steps && $i < $count; $step++, $i++) {
                    if ($at->greaterThan($realNow)) {
                        break;
                    }

                    // Multi-step chains often start with a retrieval embedding.
                    $spec = $steps > 1 && $step === 0 && mt_rand(0, 1) === 0
                        ? $this->mix[2]
                        : $this->mix[$this->pick($weights)];

                    $at = $this->recordOne($spec, $at, $user, $source)->addMilliseconds(mt_rand(40, 900));
                }

                if ($step === 0) {
                    $i++;
                }
            }
        }

        Carbon::setTestNow();
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: int, 5: array{int, int}, 6: array{int, int}}  $spec
     */
    protected function recordOne(array $spec, Carbon $at, User $user, string $source): Carbon
    {
        [$provider, $model, $type, $endpoint, , $promptRange, $completionRange] = $spec;

        Carbon::setTestNow($at);

        $streaming = $type === 'text' && mt_rand(1, 100) <= 45;
        $context = Spectra::startRequest($provider, $model, [
            'endpoint' => $endpoint,
            'operation' => 'POST',
            'trackable_type' => $user->getMorphClass(),
            'trackable_id' => $user->getKey(),
            'tags' => mt_rand(1, 100) <= 60 ? [$this->tags[array_rand($this->tags)]] : [],
        ]);
        $context->modelType = $type;
        $context->isStreaming = $streaming;
        $context->requestSource = $source;

        $latency = match ($type) {
            'video' => mt_rand(40_000, 140_000),
            'image' => mt_rand(6_000, 22_000),
            'tts' => mt_rand(500, 4_000),
            'stt' => mt_rand(600, 3_500),
            'embedding' => mt_rand(80, 600),
            default => mt_rand(400, 9_000),
        };

        if ($streaming) {
            $context->setTimeToFirstToken(mt_rand(180, 1_400));
        }

        Carbon::setTestNow($at->copy()->addMilliseconds($latency));

        if (mt_rand(1, 100) <= 3) {
            [$status, $message] = mt_rand(0, 1) === 0
                ? [429, 'Rate limit reached for requests per minute. Please try again in 2s.']
                : [500, 'The server had an error while processing your request.'];

            Spectra::recordFailure($context, new ProviderErrorException($message, $status), $status);

            return $at->copy()->addMilliseconds($latency);
        }

        $prompt = mt_rand(...$promptRange);
        $completion = mt_rand(...$completionRange);
        $cached = $type === 'text' && mt_rand(1, 100) <= 40 ? (int) ($prompt * mt_rand(30, 85) / 100) : 0;

        if ($type === 'stt') {
            $context->durationSeconds = mt_rand(8, 600);
        }

        if ($type === 'image') {
            $context->imageCount = 1;
        }

        // Media handlers read their billing units from the request, as they do live.
        $context->requestData = match ($type) {
            'tts' => [$provider === 'elevenlabs' ? 'text' : 'input' => str_repeat('Thanks for calling, how can I help you today? ', mt_rand(2, 60))],
            'video' => ['instances' => [['prompt' => 'A drone shot over a coastline at sunset']], 'parameters' => ['durationSeconds' => [4, 6, 8][mt_rand(0, 2)]]],
            default => null,
        };

        if ($type === 'tts') {
            // ~15 characters per second of speech.
            $context->durationSeconds = round(mb_strlen((string) reset($context->requestData)) / 15, 1);
        }

        $body = match (true) {
            $type === 'video' => ['name' => 'operations/'.bin2hex(random_bytes(6)), 'done' => true, 'response' => ['generateVideoResponse' => ['generatedSamples' => [['video' => ['uri' => 'https://example.test/demo.mp4']]]]]],
            $type === 'tts' => [],
            $provider === 'replicate' => ['id' => bin2hex(random_bytes(8)), 'model' => $model, 'status' => 'succeeded', 'output' => 'https://example.test/demo.webp', 'metrics' => ['image_count' => 1]],
            $type === 'image' => ['created' => $at->timestamp, 'data' => [['url' => 'https://example.test/demo.png']], 'usage' => ['input_tokens' => $prompt, 'output_tokens' => $completion, 'input_tokens_details' => ['text_tokens' => $prompt, 'image_tokens' => 0]]],
            $type === 'stt' => ['text' => 'Demo transcript.', 'duration' => $context->durationSeconds, 'x_groq' => ['id' => 'req_'.bin2hex(random_bytes(6))]],
            default => ['id' => 'demo_'.bin2hex(random_bytes(8)), 'model' => $model, 'content' => 'Demo response.'],
        };

        Spectra::recordSuccess($context, $body, [
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'cached_tokens' => $cached,
            'image_output_tokens' => $type === 'image' ? $completion : 0,
            'reasoning_tokens' => $type === 'text' && mt_rand(1, 100) <= 30 ? (int) ($completion * 0.4) : 0,
        ]);

        return $at->copy()->addMilliseconds($latency);
    }

    /**
     * @param  list<int>  $weights
     */
    protected function pick(array $weights): int
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $index => $weight) {
            if (($roll -= $weight) <= 0) {
                return $index;
            }
        }

        return 0;
    }
}
