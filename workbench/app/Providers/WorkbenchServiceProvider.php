<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Workbench\App\Models\User;

class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewSpectra', fn (?object $user = null) => true);

        // Demo alert rules for the dashboard's Alerts card; the seeded traffic
        // has a few rate-limited calls and slow image models.
        config()->set('spectra.alerts.rules', config('spectra.alerts.rules') ?: [
            ['name' => 'High error rate', 'metric' => 'error_rate', 'above' => 0.01, 'window' => 1440],
            ['name' => 'Slow responses', 'metric' => 'latency_p95', 'above' => 60000, 'window' => 1440],
            ['name' => 'Daily spend', 'metric' => 'cost', 'above' => 5000, 'window' => 1440],
        ]);

        // Demo review queues for the Review screen: a sample of support answers,
        // and a queue reviewers fill by hand from a trace's page.
        config()->set('spectra.annotations.queues', config('spectra.annotations.queues') ?: [
            'support-review' => [
                'description' => 'Check the support agent\'s answers',
                'scores' => [
                    'helpful' => 'boolean',
                    'accuracy' => ['type' => 'numeric', 'min' => 1, 'max' => 5],
                    'issue' => ['type' => 'categorical', 'options' => ['wrong-facts', 'tone', 'incomplete', 'none']],
                ],
                'sample' => 0.25,
                'filter' => ['prompt_name' => 'support-answer'],
            ],
            'escalations' => [
                'description' => 'Traces a teammate flagged',
                'scores' => ['needs_fix' => 'boolean'],
            ],
        ]);

        $this->registerDashboardsDemo();

        // The demo's Playground runs against a fake provider, so trying it here
        // or retaking its screenshots needs no key and costs nothing.
        if (! $this->app->runningInConsole()) {
            $this->fakePlaygroundProvider();
        }
    }

    /**
     * Turn dashboards on, with a way to open one as its viewer: the demo
     * has no login, so /workbench/view-as/{id} logs in as that user.
     */
    protected function registerDashboardsDemo(): void
    {
        config()->set('spectra.dashboards.enabled', true);
        config()->set('auth.providers.users.model', User::class);

        Route::middleware('web')->group(function () {
            Route::get('/login', fn () => 'The demo has no login page. Open /workbench/view-as/{id} to log in as a user.')->name('login');
            Route::get('/workbench/view-as/{id}', function (string $id) {
                Auth::loginUsingId($id);

                return redirect()->intended('/spectra');
            });
        });
    }

    /**
     * Answer the Playground's chat calls with a canned reply in each API's shape.
     * A system prompt that asks for it gets a short answer, so an edit shows.
     */
    protected function fakePlaygroundProvider(): void
    {
        config()->set('spectra.dashboard.playground.enabled', true);
        config()->set('spectra.api_keys', [
            'openai' => 'demo',
            'anthropic' => 'demo',
            'google' => 'demo',
            'groq' => 'demo',
            'xai' => 'demo',
            'mistral' => 'demo',
            'openrouter' => 'demo',
            'cohere' => 'demo',
        ]);

        Http::fake(fn (Request $request) => $this->fakeChatResponse($request));
    }

    /**
     * Get the canned answer for a chat call, in the shape of its endpoint.
     *
     * @return PromiseInterface|Response|null
     */
    protected function fakeChatResponse(Request $request): mixed
    {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        if (! Str::contains($path, ['chat/completions', '/responses', '/messages', ':generateContent', '/v2/chat'])) {
            return null;
        }

        // A model name that says so fails, to show how a failed run looks.
        if (Str::contains((string) ($request['model'] ?? ''), 'does-not-exist')) {
            Sleep::for(210)->milliseconds();

            return Http::response(['error' => ['message' => "The model `{$request['model']}` does not exist or you do not have access to it.", 'type' => 'invalid_request_error', 'code' => 'model_not_found']], 404);
        }

        $body = $request->body();
        $wantsShort = Str::contains(Str::lower($body), ['short', 'concise', 'one sentence']);
        $model = $request['model'] ?? 'gemini-2.5-flash';

        $text = $wantsShort
            ? 'Refunds reach your card within 5 to 10 business days of approval.'
            : "Thanks for reaching out! Once a refund is approved, it usually takes 5 to 10 business days to appear on your card, depending on your bank.\n\nYou can check its status at any time under Orders in your account. If it still hasn't arrived after 10 business days, reply here with your order number and I'll look into it with our payments team.";

        // Roughly four characters to a token, as the provider would report it.
        $promptTokens = (int) ceil(strlen($body) / 4);
        $replyTokens = (int) ceil(strlen($text) / 4);

        Sleep::for($wantsShort ? 640 : 1180)->milliseconds();

        // Each reply gets its own id: Spectra treats a repeated one as the same call.
        return Http::response(match (true) {
            Str::contains($path, ':generateContent') => ['modelVersion' => $model, 'candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => $promptTokens, 'candidatesTokenCount' => $replyTokens, 'totalTokenCount' => $promptTokens + $replyTokens]],
            Str::endsWith($path, '/responses') => ['id' => 'resp_'.Str::random(16), 'object' => 'response', 'status' => 'completed', 'model' => $model, 'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]], 'usage' => ['input_tokens' => $promptTokens, 'output_tokens' => $replyTokens, 'total_tokens' => $promptTokens + $replyTokens]],
            Str::endsWith($path, '/messages') => ['id' => 'msg_'.Str::random(16), 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => [['type' => 'text', 'text' => $text]], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => $promptTokens, 'output_tokens' => $replyTokens]],
            default => ['id' => 'chatcmpl_'.Str::random(16), 'object' => 'chat.completion', 'model' => $model, 'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => $text], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => $promptTokens, 'completion_tokens' => $replyTokens, 'total_tokens' => $promptTokens + $replyTokens]],
        });
    }
}
