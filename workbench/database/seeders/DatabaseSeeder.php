<?php

declare(strict_types=1);

namespace Workbench\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Spectra\Enums\ScoreSource;
use Spectra\Enums\SpanKind;
use Spectra\Facades\Spectra;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Tracking\ProviderErrorException;
use Spectra\Support\Tracking\SpanStack;
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

    /**
     * Support-chat exchanges for the demo conversations: what the user asks, what the model answers.
     *
     * @var list<array{string, string}>
     */
    protected array $dialogue = [
        ['Where is my order #4821?', 'Your order #4821 shipped yesterday and should arrive on Thursday. Here is the tracking link: https://example.test/track/4821'],
        ['Can I change the delivery address?', 'Yes. I have updated the delivery address to the one on your profile. You will get a confirmation email shortly.'],
        ['How do I reset my password?', 'Open Settings, then Security, and choose "Reset password". We will email you a link that is valid for 30 minutes.'],
        ['Do you ship to Canada?', 'We do. Orders to Canada usually arrive in 5 to 8 business days, and duties are included at checkout.'],
        ['What is your refund policy?', 'You can return any item within 30 days for a full refund. Start the return from your order page and print the label.'],
        ['Can you summarize my last three invoices?', 'Your last three invoices total $1,240: $480 in September, $410 in October and $350 in November. All three are paid.'],
        ['Is the Pro plan billed monthly or yearly?', 'Both. Monthly is $29, and yearly is $290, which saves you two months.'],
        ['Thanks, that is all!', 'Happy to help. Have a great day!'],
    ];

    /**
     * Where a unit of work starts: the route of a web request, or the job class of a queued one.
     *
     * @var array<string, list<string>>
     */
    protected array $origins = [
        'web' => ['POST /api/chat', 'POST /api/chats/{chat}/messages', 'POST /search', 'POST /documents/{document}/summary', 'GET /dashboard'],
        'queue' => ['App\\Jobs\\SummarizeDocument', 'App\\Jobs\\ClassifyTicket', 'App\\Jobs\\GenerateWeeklyDigest'],
    ];

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

        /** @var array<int, array{id: string, turn: int, at: Carbon}> $chats the open chat of each user */
        $chats = [];

        // Each request freezes the clock at its own time, so remember the real time first.
        $realNow = Carbon::now();
        $today = $realNow->copy()->startOfDay();
        $this->promptCutover = $today->copy()->subDays(12);

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
                $origin = $this->origins[$source][mt_rand(0, count($this->origins[$source]) - 1)];

                // A chat message continues the user's open chat when it comes later
                // than the last message there, and now and then starts a new one.
                $conversation = null;

                if ($origin === 'POST /api/chats/{chat}/messages') {
                    $open = $chats[$user->id] ?? null;

                    if ($open === null || $open['at']->greaterThan($at) || mt_rand(1, 100) <= 25) {
                        $open = ['id' => 'chat_'.bin2hex(random_bytes(4)), 'turn' => 0, 'at' => $at];
                    }

                    $open['turn']++;
                    $open['at'] = $at->copy();
                    $chats[$user->id] = $open;
                    $conversation = $open;
                }
                $steps = [1, 1, 1, 1, 1, 2, 2, 3, 4, 5][mt_rand(0, 9)];

                // The unit of work is the trace's root span, as a real request or job is.
                $unitKey = 'demo:'.$i;
                Carbon::setTestNow($at);
                app(SpanStack::class)->startUnit($unitKey, $source === 'queue' ? SpanKind::Job : SpanKind::Request, $origin, $at->copy()->subMilliseconds(mt_rand(5, 60)));

                for ($step = 0; $step < $steps && $i < $count; $step++, $i++) {
                    if ($at->greaterThan($realNow)) {
                        break;
                    }

                    // Multi-step chains often start with a retrieval embedding, inside a span the app opens.
                    $retrieval = $steps > 1 && $step === 0 && mt_rand(0, 1) === 0;
                    $spec = $retrieval ? $this->mix[2] : $this->mix[$this->pick($weights)];

                    $at = $retrieval
                        ? Spectra::span('retrieve-context', function () use ($spec, $at, $user, $source, $origin, $conversation) {
                            $end = $this->recordOne($spec, $at, $user, $source, $origin, $conversation);
                            // The vector search after the embedding.
                            Carbon::setTestNow($end->copy()->addMilliseconds(mt_rand(20, 120)));

                            return Carbon::now();
                        }, ['top_k' => 5])->addMilliseconds(mt_rand(40, 900))
                        : $this->recordOne($spec, $at, $user, $source, $origin, $conversation)->addMilliseconds(mt_rand(40, 900));

                    // Between the steps of a chain, the model often asks for tools the app runs.
                    if ($spec[2] === 'text' && $step < $steps - 1 && mt_rand(1, 100) <= 60) {
                        $at = $this->runTools($at);
                    }
                }

                Carbon::setTestNow($at->copy()->addMilliseconds(mt_rand(10, 80)));
                $traceId = Spectra::currentTraceId();
                app(SpanStack::class)->finishUnit($unitKey);

                if ($conversation !== null && $traceId !== null) {
                    $this->scoreChatTurn($traceId, $conversation['id'], $user);
                }

                if ($step === 0) {
                    $i++;
                }
            }
        }

        Carbon::setTestNow();

        $this->seedRedactedCall($users->first());
    }

    /**
     * Run one or two of the support agent's tools with Spectra::tool(), as
     * the app would between two calls of a chain, and return when they ended.
     */
    protected function runTools(Carbon $at): Carbon
    {
        $tools = [
            ['search_docs', ['query' => 'refund policy for annual plans', 'limit' => 3], [['title' => 'Refunds and cancellations', 'score' => 0.91], ['title' => 'Annual billing', 'score' => 0.84], ['title' => 'Changing plans', 'score' => 0.62]]],
            ['get_order', ['order_id' => 4821], ['id' => 4821, 'status' => 'shipped', 'total' => 129.0, 'currency' => 'EUR', 'carrier' => 'DHL']],
            ['lookup_customer', ['customer_id' => 'cus_7Hq2'], ['plan' => 'pro', 'seats' => 12, 'since' => '2024-03-18']],
            ['create_ticket', ['subject' => 'Double charge on invoice INV-2291', 'priority' => 'high'], ['ticket_id' => 'T-10482', 'status' => 'open']],
        ];

        foreach (range(1, mt_rand(1, 2)) as $ignored) {
            [$name, $arguments, $result] = $tools[mt_rand(0, count($tools) - 1)];
            Carbon::setTestNow($at);
            $fails = $name === 'get_order' && mt_rand(1, 100) <= 15;

            try {
                Spectra::tool($name, function () use ($at, $fails, $result) {
                    Carbon::setTestNow($at->copy()->addMilliseconds(mt_rand(30, 1_200)));

                    throw_if($fails, RuntimeException::class, 'Order service timed out after 1s');

                    return $result;
                }, $arguments, id: 'call_'.bin2hex(random_bytes(6)));
            } catch (RuntimeException) {
                // The agent tells the model the tool failed and carries on.
            }

            $at = Carbon::now()->addMilliseconds(mt_rand(5, 40));
        }

        return $at;
    }

    /**
     * One support call tracked with redaction on, so the dashboard shows what
     * a masked prompt and reply look like.
     */
    protected function seedRedactedCall(User $user): void
    {
        config()->set('spectra.storage.redaction.enabled', true);
        Spectra::newTrace();

        Spectra::track('openai', 'gpt-5.4-mini', fn () => [
            'id' => 'chatcmpl-redaction-demo',
            'model' => 'gpt-5.4-mini',
            'choices' => [['index' => 0, 'finish_reason' => 'stop', 'message' => [
                'role' => 'assistant',
                'content' => 'Thanks! I sent the refund confirmation to jonas.berg@example.com. The card ending 4242 will be credited in 3-5 days.',
            ]]],
            'usage' => ['prompt_tokens' => 64, 'completion_tokens' => 31],
        ], [
            'endpoint' => '/v1/chat/completions',
            'trackable_type' => $user->getMorphClass(),
            'trackable_id' => $user->getKey(),
            'request_data' => ['model' => 'gpt-5.4-mini', 'messages' => [
                ['role' => 'user', 'content' => 'Refund order 1234567812345678 to card 4242 4242 4242 4242. Email jonas.berg@example.com or call +49 30 1234 5678. My old key was sk-proj-Abc123def456ghi789jkl012mno345.'],
            ]],
        ]);

        config()->set('spectra.storage.redaction.enabled', false);
    }

    /** When support-answer v3 replaced v2. */
    protected ?Carbon $promptCutover = null;

    /**
     * The prompt template and version a demo call used, if any.
     *
     * @param  array{id: string, turn: int, at: Carbon}|null  $conversation
     * @return array{0: ?string, 1: ?string}
     */
    protected function promptFor(string $type, string $origin, ?array $conversation, ?Carbon $at = null): array
    {
        if ($conversation !== null && $type === 'text') {
            return ['support-answer', $at !== null && $this->promptCutover !== null && $at->lessThan($this->promptCutover) ? 'v2' : 'v3'];
        }

        if ($origin === 'App\\Jobs\\SummarizeDocument' && $type === 'text') {
            return ['document-summary', 'v1'];
        }

        return [null, null];
    }

    /**
     * Score some chat answers the way an app would: the user's thumbs up or
     * down on the answer, a model's 1-5 rating of the last call, and now and
     * then the conversation's sentiment.
     */
    protected function scoreChatTurn(string $traceId, string $conversationId, User $user): void
    {
        $lastCall = SpectraRequest::query()->where('trace_id', $traceId)->latest('created_at')->value('id');

        if (mt_rand(1, 100) <= 45) {
            $helpful = mt_rand(1, 100) <= 78;
            $comments = $helpful
                ? [null, null, null, 'Exactly what I needed', 'Clear and quick, thanks']
                : [null, 'It ignored my order number', 'Too long, I just wanted the steps', 'Wrong refund policy'];

            Spectra::score('helpful', $helpful, traceId: $traceId, comment: $comments[mt_rand(0, count($comments) - 1)], source: ScoreSource::User, author: $user);
        }

        if ($lastCall !== null && mt_rand(1, 100) <= 35) {
            Spectra::score('answer_quality', mt_rand(25, 50) / 10, request: $lastCall, source: ScoreSource::Model, metadata: ['judge' => 'gpt-5.4-mini']);
        }

        if (mt_rand(1, 100) <= 10) {
            $sentiments = ['positive', 'positive', 'neutral', 'negative'];
            Spectra::score('sentiment', $sentiments[mt_rand(0, 3)], conversationId: $conversationId, source: ScoreSource::Model);
        }
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: string, 4: int, 5: array{int, int}, 6: array{int, int}}  $spec
     * @param  array{id: string, turn: int, at: Carbon}|null  $conversation
     */
    protected function recordOne(array $spec, Carbon $at, User $user, string $source, string $origin, ?array $conversation = null): Carbon
    {
        [$provider, $model, $type, $endpoint, , $promptRange, $completionRange] = $spec;

        Carbon::setTestNow($at);

        $streaming = $type === 'text' && mt_rand(1, 100) <= 45;
        $context = Spectra::startRequest($provider, $model, [
            'endpoint' => $endpoint,
            'operation' => 'POST',
            'trackable_type' => $user->getMorphClass(),
            'trackable_id' => $user->getKey(),
            'origin' => $origin,
            'conversation_id' => $conversation['id'] ?? null,
            'conversation_turn' => $conversation['turn'] ?? null,
            'tags' => mt_rand(1, 100) <= 60 ? [$this->tags[array_rand($this->tags)]] : [],
            // Chat answers and document summaries come from versioned prompts;
            // support-answer v3 replaced v2 twelve days ago.
            'prompt_name' => $this->promptFor($type, $origin, $conversation)[0],
            'prompt_version' => $this->promptFor($type, $origin, $conversation, $at)[1],
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
            [$status, $message] = $this->failureFor($provider, $model);

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

        // A chat turn stores the user's message and the provider's real reply shape, for the transcript.
        $exchange = $conversation !== null && $type === 'text'
            ? $this->dialogue[($conversation['turn'] - 1 + crc32($conversation['id'])) % count($this->dialogue)]
            : null;

        // Media handlers read their billing units from the request, as they do live.
        $context->requestData = $exchange !== null ? $this->chatRequest($endpoint, $exchange[0]) : match ($type) {
            'tts' => [$provider === 'elevenlabs' ? 'text' : 'input' => str_repeat('Thanks for calling, how can I help you today? ', mt_rand(2, 60))],
            'video' => ['instances' => [['prompt' => 'A drone shot over a coastline at sunset']], 'parameters' => ['durationSeconds' => [4, 6, 8][mt_rand(0, 2)]]],
            default => [],
        };

        if ($type === 'tts') {
            // ~15 characters per second of speech.
            $context->durationSeconds = round(mb_strlen((string) reset($context->requestData)) / 15, 1);
        }

        $body = $exchange !== null ? $this->chatReply($endpoint, $model, $exchange[1]) : match (true) {
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
     * A provider failure, with the ids and numbers that change from one call
     * to the next, so the Errors screen has real groups to show.
     *
     * @return array{0: int, 1: string}
     */
    protected function failureFor(string $provider, string $model): array
    {
        $roll = mt_rand(1, 100);

        return match (true) {
            $provider === 'anthropic' && $roll <= 40 => [529, 'Overloaded'],
            $roll <= 45 => [429, sprintf('Rate limit reached for %s in organization org-%s on tokens per min (TPM): Limit 30000, Used %d, Requested %d. Please try again in %ss.', $model, bin2hex(random_bytes(6)), mt_rand(24_000, 29_900), mt_rand(800, 6_000), number_format(mt_rand(5, 300) / 10, 1))],
            $roll <= 70 => [500, sprintf('The server had an error while processing your request. Sorry about that! You can retry your request, or contact us through our help center if the error persists. (Please include the request ID req_%s in your message.)', bin2hex(random_bytes(12)))],
            $roll <= 90 => [400, sprintf("This model's maximum context length is 128000 tokens. However, your messages resulted in %d tokens. Please reduce the length of the messages.", mt_rand(128_100, 190_000))],
            default => [401, sprintf('Incorrect API key provided: sk-proj-****%s. You can find your API key at https://platform.openai.com/account/api-keys.', Str::lower(Str::random(4)))],
        };
    }

    /**
     * The request body a chat API gets for one user message.
     *
     * @return array<string, mixed>
     */
    protected function chatRequest(string $endpoint, string $message): array
    {
        return match (true) {
            str_contains($endpoint, ':generateContent') => ['contents' => [['role' => 'user', 'parts' => [['text' => $message]]]]],
            $endpoint === '/v1/responses' => ['input' => $message],
            default => ['messages' => [['role' => 'system', 'content' => 'You are a helpful support agent.'], ['role' => 'user', 'content' => $message]]],
        };
    }

    /**
     * The response body a chat API returns, in the shape of its endpoint.
     *
     * @return array<string, mixed>
     */
    protected function chatReply(string $endpoint, string $model, string $text): array
    {
        $id = 'demo_'.bin2hex(random_bytes(8));

        return match (true) {
            str_contains($endpoint, ':generateContent') => ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP']], 'modelVersion' => $model],
            $endpoint === '/v1/responses' => ['id' => $id, 'object' => 'response', 'model' => $model, 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]]],
            $endpoint === '/v1/messages' => ['id' => $id, 'type' => 'message', 'model' => $model, 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn'],
            default => ['id' => $id, 'object' => 'chat.completion', 'model' => $model, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']]],
        };
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
