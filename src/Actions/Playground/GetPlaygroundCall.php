<?php

declare(strict_types=1);

namespace Spectra\Actions\Playground;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Spectra\Data\PlaygroundCall;
use Spectra\Data\Responses\PlaygroundCallResponse;
use Spectra\Data\Responses\PlaygroundRunResponse;
use Spectra\Models\SpectraRequest;
use Spectra\Support\MessageTextExtractor;
use Spectra\Support\Playground\PlaygroundClient;
use Spectra\Support\Pricing\PricingLookup;
use Spectra\Support\Tracking\RequestDataFilter;

/**
 * Everything the Playground needs to open one stored call: the body to edit,
 * the provider's models, the runs that belong with it, and what the stored body
 * may have lost, since a run sends what was stored rather than what the app first
 * sent. A call sent from the Playground opens as a run, next to its original.
 */
class GetPlaygroundCall
{
    /**
     * Create a new get playground call action instance.
     */
    public function __construct(
        private readonly PlaygroundClient $client,
        private readonly MessageTextExtractor $textExtractor,
        private readonly PricingLookup $pricingLookup,
        private readonly GetPlaygroundRuns $getPlaygroundRuns,
    ) {}

    /**
     * Get the stored call as the Playground opens it.
     */
    public function __invoke(SpectraRequest $call): PlaygroundCallResponse
    {
        $body = is_array($call->request) ? $this->client->prepareBody($call->provider, $call->request) : null;
        $saved = ($this->getPlaygroundRuns)($this->client->sourceOf($call) ?? $call->id, $this->client->sessionOf($call));

        return new PlaygroundCallResponse(
            call: PlaygroundCall::fromModel($call, $this->textExtractor->prompt($call->request), rescue(fn () => $this->textExtractor->reply($call), report: false)),
            provider: $call->provider,
            endpoint: $call->endpoint,
            body: $body,
            model: $this->client->modelOf($call),
            models: $this->pricingLookup->textModels($call->provider),
            base_url: $this->client->baseUrl($call->provider),
            notices: $this->notices($call),
            unavailable_reason: $this->client->unavailableReason($call),
            runs: array_values(array_filter($saved['runs'], fn (PlaygroundRunResponse $run): bool => $run->request_id !== $call->id)),
            run_count: $saved['count'],
            run: $this->openedRun($call, $saved['runs']),
            original: $this->original($call),
        );
    }

    /**
     * Get the opened call as a run when it was sent from the Playground. It isn't
     * among the saved runs when it is older than they reach, and then has no number.
     *
     * @param  array<int, PlaygroundRunResponse>  $saved
     */
    private function openedRun(SpectraRequest $call, array $saved): ?PlaygroundRunResponse
    {
        if (! $call->tags()->where('name', PlaygroundClient::TAG)->exists()) {
            return null;
        }

        return collect($saved)->firstWhere('request_id', $call->id)
            ?? PlaygroundRunResponse::fromModel($call, $this->client->modelOf($call), rescue(fn () => $this->textExtractor->reply($call), report: false));
    }

    /**
     * Get the stored call a run started from, to compare with. A new request's
     * runs have no original, so a run in a session has none either, even when it
     * was sent from another run's page. The original may have been pruned.
     */
    private function original(SpectraRequest $call): ?PlaygroundCall
    {
        $sourceId = $this->client->sourceOf($call);
        $source = $sourceId !== null && $this->client->sessionOf($call) === null ? SpectraRequest::query()->find($sourceId) : null;

        return $source !== null
            ? PlaygroundCall::fromModel($source, $this->textExtractor->prompt($source->request), rescue(fn () => $this->textExtractor->reply($source), report: false))
            : null;
    }

    /**
     * Get what makes a run differ from the original call, in plain words.
     *
     * @return list<string>
     */
    private function notices(SpectraRequest $call): array
    {
        if (! is_array($call->request)) {
            return [];
        }

        $notices = [];
        $values = collect(Arr::flatten($call->request))->filter(fn (mixed $value): bool => is_string($value));

        if ($values->contains(fn (string $value): bool => Str::contains($value, '[REDACTED'))) {
            $notices[] = 'Redaction masked parts of this call. The masks, such as [REDACTED:email], are sent as written unless you replace them.';
        }

        if ($values->contains(RequestDataFilter::STRIPPED)) {
            $notices[] = 'This call carried inline files, which aren\'t stored. Remove the [stripped] parts in the JSON tab, or the provider may reject the run.';
        }

        if (! config('spectra.storage.store_system_prompts', true)) {
            $notices[] = 'System prompts aren\'t stored (store_system_prompts is off), so this call\'s system prompt is missing. Add it back to compare fairly.';
        }

        if (! config('spectra.storage.store_tools', true) || ($call->has_tool_calls && blank($call->request['tools'] ?? null) && blank($call->request['functions'] ?? null))) {
            $notices[] = 'Tool definitions aren\'t stored with this call, so the model can\'t ask for those tools here. Add them back in the JSON tab to compare fairly.';
        }

        // Azure OpenAI calls are stored as `openai` with the host left out, so
        // one can't be told apart from a call to OpenAI itself.
        if ($call->provider === 'openai' && (filled(config('spectra.endpoint_urls.azure')) || filled(config('ai.providers.azure.url')))) {
            $notices[] = 'This app also uses Azure OpenAI. Stored calls don\'t say which host they went to, so a run always goes to the OpenAI address shown below, never to Azure.';
        }

        if ($call->is_streaming) {
            $notices[] = 'This call was streamed. A run here waits for the whole answer, so its latency is end to end and has no time to first token.';
        }

        return $notices;
    }
}
