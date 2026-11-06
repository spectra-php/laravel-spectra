<?php

declare(strict_types=1);

namespace Spectra\Support\Playground;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spectra\Data\Responses\PlaygroundRunResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Spectra;
use Spectra\Support\ApiKeyResolver;
use Spectra\Support\MessageTextExtractor;
use Spectra\Support\ProviderRegistry;
use Spectra\Support\Redaction\Redactor;
use Spectra\Support\Tracking\RecordingSwitch;

/**
 * Sends a chat call to a provider for the dashboard's Playground: a stored call
 * again with edits, or a new prompt.
 *
 * The endpoint always comes from the stored call or the list of chat endpoints
 * below, and the base URL and key from the app's config, never from the browser,
 * so a run can only reach a provider's chat API. The call goes through
 * `withAITracking()`, so a run is recorded and priced like any other call, and
 * is tagged `playground`.
 */
class PlaygroundClient
{
    /** The tag on every call the Playground sends. */
    public const TAG = 'playground';

    /** The metadata key that names the call a run started from. */
    public const SOURCE_METADATA_KEY = 'playground_source';

    /** The metadata key that names the new-prompt session a run belongs to. */
    public const SESSION_METADATA_KEY = 'playground_session';

    /**
     * The chat endpoints a run can be sent to, per provider. A Gemini endpoint
     * names its model, so it is matched by GEMINI_ENDPOINT instead.
     *
     * @var array<string, list<string>>
     */
    protected const ENDPOINTS = [
        'openai' => ['/v1/chat/completions', '/v1/responses'],
        'anthropic' => ['/v1/messages'],
        'groq' => ['/openai/v1/chat/completions', '/openai/v1/responses'],
        'xai' => ['/v1/chat/completions', '/v1/responses'],
        'mistral' => ['/v1/chat/completions'],
        'openrouter' => ['/api/v1/chat/completions'],
        'cohere' => ['/v2/chat'],
        'ollama' => ['/api/chat', '/api/generate'],
    ];

    /**
     * The endpoint and starting body of a new prompt, per provider, in the order
     * the screen lists them. Anthropic refuses a call without `max_tokens`. A
     * Gemini endpoint's model is filled in when the prompt runs.
     *
     * @var array<string, string>
     */
    protected const NEW_PROMPT_ENDPOINTS = [
        'openai' => '/v1/chat/completions',
        'anthropic' => '/v1/messages',
        'google' => '/v1beta/models/model:generateContent',
        'groq' => '/openai/v1/chat/completions',
        'mistral' => '/v1/chat/completions',
        'openrouter' => '/api/v1/chat/completions',
        'xai' => '/v1/chat/completions',
        'cohere' => '/v2/chat',
        'ollama' => '/api/chat',
    ];

    /** A Gemini chat endpoint: its version, its model, and whether it streams. */
    protected const GEMINI_ENDPOINT = '#^/([^/]+)/models/([^/:]+):(?:stream)?generateContent$#i';

    /** Ollama streams unless told not to, and needs no API key. */
    protected const KEYLESS_PROVIDER = 'ollama';

    /**
     * Keys whose value is a JSON object in the chat APIs. A stored body comes
     * back from the database as PHP arrays, so an empty `{}` has become `[]`,
     * which providers reject where they expect an object.
     */
    protected const OBJECT_KEYS = ['properties', 'parameters', 'input_schema', 'schema', 'metadata', 'generationConfig', 'options', 'arguments'];

    /**
     * Create a new playground client instance.
     */
    public function __construct(
        protected ApiKeyResolver $apiKeyResolver,
        protected ProviderRegistry $providers,
        protected MessageTextExtractor $textExtractor,
        protected Spectra $spectra,
    ) {}

    /**
     * Get the provider slugs a run can be sent to.
     *
     * @return list<string>
     */
    public function providers(): array
    {
        return array_keys(self::NEW_PROMPT_ENDPOINTS);
    }

    /**
     * Determine if the provider's key is configured, or the provider needs none.
     */
    public function hasKey(string $provider): bool
    {
        return $provider === self::KEYLESS_PROVIDER || filled($this->apiKeyResolver->resolve($provider));
    }

    /**
     * Get the endpoint a new prompt to the provider goes to.
     */
    public function newPromptEndpoint(string $provider): string
    {
        return self::NEW_PROMPT_ENDPOINTS[$provider];
    }

    /**
     * Get the body a new prompt to the provider starts from.
     *
     * @return array<string, mixed>
     */
    public function newPromptBody(string $provider): array
    {
        return match ($provider) {
            'anthropic' => ['max_tokens' => 1024, 'messages' => [['role' => 'user', 'content' => '']]],
            'google' => ['contents' => [['role' => 'user', 'parts' => [['text' => '']]]]],
            default => ['messages' => [['role' => 'user', 'content' => '']]],
        };
    }

    /**
     * Determine if a stored call can be opened in the Playground: a text call
     * to a chat endpoint, with its request body stored.
     */
    public function supports(SpectraRequest $call): bool
    {
        return $this->isChatCall($call) && filled($call->request);
    }

    /**
     * Get the reason a stored call can't be run, or null when it can.
     */
    public function unavailableReason(SpectraRequest $call): ?string
    {
        if (! $this->isChatCall($call)) {
            return 'The Playground runs text calls to a provider\'s chat endpoint. This call went to '.($call->endpoint ?? 'an unknown endpoint').' at '.$this->providers->displayName($call->provider).'.';
        }

        if (blank($call->request)) {
            return 'This call\'s request body wasn\'t stored, so there is nothing to run. Payload sampling or the storage settings left it out.';
        }

        return $this->missingKeyReason($call->provider);
    }

    /**
     * Get the message to show when the provider's key isn't configured, or null when it is.
     */
    public function missingKeyReason(string $provider): ?string
    {
        if ($this->hasKey($provider)) {
            return null;
        }

        return "No API key was found for {$this->providers->displayName($provider)}. Set spectra.api_keys.{$provider} in config/spectra.php, or the service's usual config key.";
    }

    /**
     * Get the provider's id of the model a stored call used.
     */
    public function modelOf(SpectraRequest $call): string
    {
        $model = $call->request['model'] ?? null;

        if (is_string($model) && filled($model)) {
            return $model;
        }

        if ($call->provider === 'google' && preg_match(self::GEMINI_ENDPOINT, (string) $call->endpoint, $matches) === 1) {
            return $matches[2];
        }

        return $call->snapshot ?? $call->model;
    }

    /**
     * Get the stored call a run started from, if any. A run sent from another
     * run's page names the same call, so a call's runs all point at it.
     */
    public function sourceOf(SpectraRequest $call): ?string
    {
        $source = $call->metadata[self::SOURCE_METADATA_KEY] ?? null;

        return is_string($source) && Str::isUuid($source) ? $source : null;
    }

    /**
     * Get the new-prompt session a stored call belongs to, if any.
     */
    public function sessionOf(SpectraRequest $call): ?string
    {
        $session = $call->metadata[self::SESSION_METADATA_KEY] ?? null;

        return is_string($session) ? $session : null;
    }

    /**
     * Get the body to send: streaming and background mode off, since a run is
     * read in one piece, and empty JSON objects restored.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function prepareBody(string $provider, array $body): array
    {
        $body = Arr::except($body, ['stream', 'stream_options', 'background']);

        if ($provider === self::KEYLESS_PROVIDER) {
            $body['stream'] = false;
        }

        return $this->withEmptyObjects($body);
    }

    /**
     * Send a body to the provider's endpoint, and get the result. `$sourceId` is
     * the stored call the run started from, if any, and `$sessionId` the new-prompt
     * session it belongs to. The caller takes the endpoint from the stored call or
     * newPromptEndpoint(), never from the input.
     *
     * @param  array<string, mixed>  $body
     */
    public function run(string $provider, string $endpoint, string $model, array $body, ?string $sourceId = null, ?string $sessionId = null): PlaygroundRunResponse
    {
        $endpoint = $this->endpointFor($provider, $endpoint, $model);
        $payload = $this->prepareBody($provider, $body);

        // A Gemini endpoint names the model; every other API reads it from the body.
        if ($provider === 'google') {
            unset($payload['model']);
        } else {
            $payload['model'] = $model;
        }

        $response = null;

        try {
            $response = $this->pendingRequest($provider, $model, $sourceId, $sessionId)->post($this->baseUrl($provider).$endpoint, $payload);
        } catch (ConnectionException) {
            // GuzzleMiddleware records the failure, and the context holds its message.
        }

        return $this->result($provider, $endpoint, $model, $response);
    }

    /**
     * Determine if a stored call is a text call to a chat endpoint. A call that
     * failed before its handler ran has no model type, and those are the calls
     * most worth trying again, so only another type rules a call out.
     */
    protected function isChatCall(SpectraRequest $call): bool
    {
        return in_array($call->model_type, ['text', null], true)
            && $this->supportsEndpoint($call->provider, (string) $call->endpoint);
    }

    /**
     * Determine if an endpoint is a chat endpoint of the provider.
     */
    protected function supportsEndpoint(string $provider, string $endpoint): bool
    {
        if ($provider === 'google') {
            return preg_match(self::GEMINI_ENDPOINT, $endpoint) === 1;
        }

        return in_array($endpoint, self::ENDPOINTS[$provider] ?? [], true);
    }

    /**
     * Get the path to call: the stored endpoint, with Gemini's model in it and
     * its streaming method swapped for the plain one.
     */
    protected function endpointFor(string $provider, string $endpoint, string $model): string
    {
        if ($provider !== 'google') {
            return $endpoint;
        }

        return Str::replaceMatches(
            self::GEMINI_ENDPOINT,
            fn (array $matches): string => "/{$matches[1]}/models/".rawurlencode($model).':generateContent',
            $endpoint,
        ) ?? $endpoint;
    }

    /**
     * Build the tracked HTTP request with the provider's way of sending a key.
     */
    protected function pendingRequest(string $provider, string $model, ?string $sourceId, ?string $sessionId): PendingRequest
    {
        $key = $this->apiKeyResolver->resolve($provider);

        $request = Http::withAITracking($provider, $model, [
            'tags' => [self::TAG],
            'metadata' => array_filter([self::SOURCE_METADATA_KEY => $sourceId, self::SESSION_METADATA_KEY => $sessionId]),
        ])->acceptJson()->timeout((int) config('spectra.dashboard.playground.timeout', 120));

        return match ($provider) {
            'anthropic' => $request->withHeaders(['x-api-key' => (string) $key, 'anthropic-version' => '2023-06-01']),
            'google' => $request->withHeaders(['x-goog-api-key' => (string) $key]),
            default => filled($key) ? $request->withToken($key) : $request,
        };
    }

    /**
     * Get the provider's base URL from the app's config, or its default host.
     * Only the scheme and host are used: configured URLs often end in the API
     * version (`https://api.openai.com/v1`), which the stored endpoint has too.
     */
    public function baseUrl(string $provider): string
    {
        $configured = $this->apiKeyResolver->resolveBaseUrl($provider);

        if (blank($configured)) {
            $scheme = $provider === self::KEYLESS_PROVIDER ? 'http' : 'https';

            return "{$scheme}://".Arr::first($this->providers->provider($provider)?->getHosts() ?? []);
        }

        // openai-php's base_uri is written without a scheme.
        $url = Str::contains($configured, '://') ? $configured : "https://{$configured}";
        $parts = parse_url($url);
        $port = isset($parts['port']) ? ":{$parts['port']}" : '';

        return ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '').$port;
    }

    /**
     * Build the run's result from what tracking recorded for the call. Reading
     * the persister's attributes keeps the cost, latency and masked error the
     * same as on the call's own page, wherever and whether the row is stored.
     */
    protected function result(string $provider, string $endpoint, string $model, ?Response $response): PlaygroundRunResponse
    {
        $context = $this->spectra->getCurrentContext();
        $attributes = $context !== null ? $this->spectra->getPersister()->buildAttributes($context) : [];
        $decoded = $response?->json();
        $responseBody = is_array($decoded) ? $decoded : null;
        $statusCode = $response?->status();
        $errorType = $attributes['error_type'] ?? null;

        return new PlaygroundRunResponse(
            succeeded: SpectraRequest::isSuccessfulOutcome($statusCode, $errorType),
            status_code: $statusCode,
            error_type: $errorType,
            error_message: $attributes['error_message'] ?? $this->fallbackError($response),
            reply: $this->reply($provider, $endpoint, $responseBody),
            response: $responseBody,
            model: $model,
            finish_reason: $attributes['finish_reason'] ?? null,
            has_tool_calls: (bool) ($attributes['has_tool_calls'] ?? false),
            prompt_tokens: (int) ($attributes['prompt_tokens'] ?? 0),
            completion_tokens: (int) ($attributes['completion_tokens'] ?? 0),
            total_cost_in_cents: (float) ($attributes['total_cost_in_cents'] ?? 0),
            latency_ms: $attributes['latency_ms'] ?? null,
            request_id: $context !== null && $this->isRecorded() ? $context->id : null,
        );
    }

    /**
     * Get the reply text of a response body through the provider's handler.
     *
     * @param  array<string, mixed>|null  $responseBody
     */
    protected function reply(string $provider, string $endpoint, ?array $responseBody): ?string
    {
        if ($responseBody === null) {
            return null;
        }

        $call = (new SpectraRequest)->forceFill(['provider' => $provider, 'endpoint' => $endpoint, 'response' => $responseBody]);

        return rescue(fn () => $this->textExtractor->reply($call), report: false);
    }

    /**
     * Get the error of a failed response when tracking is turned off and recorded none.
     */
    protected function fallbackError(?Response $response): ?string
    {
        if ($response === null) {
            return 'The provider could not be reached.';
        }

        return $response->successful() ? null : app(Redactor::class)->scrubSecrets($response->body());
    }

    /**
     * Determine if a run is saved as a row of its own.
     */
    protected function isRecorded(): bool
    {
        return $this->spectra->isEnabled()
            && config('spectra.storage.store_requests', true)
            && ! RecordingSwitch::isPaused();
    }

    /**
     * Turn the empty lists under object keys back into JSON objects.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    protected function withEmptyObjects(array $data): array
    {
        foreach ($data as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            // An Anthropic tool_use block with no arguments has `input: {}`.
            $isObjectKey = in_array($key, self::OBJECT_KEYS, true) || ($key === 'input' && ($data['type'] ?? null) === 'tool_use');

            $data[$key] = $value === [] && $isObjectKey ? (object) [] : $this->withEmptyObjects($value);
        }

        return $data;
    }
}
