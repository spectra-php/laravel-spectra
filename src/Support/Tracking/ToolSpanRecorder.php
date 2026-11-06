<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Carbon\CarbonInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Str;
use JsonSerializable;
use Spectra\Enums\SpanKind;
use Stringable;

/**
 * Records the tools a model asked for as `tool` spans of the trace.
 *
 * With `spectra.tracking.tool_spans` on, each AI call that sends tool
 * results back to the model gets a span per tool: from the end of the
 * previous call of the trace, when the model asked, to the start of this
 * one, when the results went back. Parallel tools share that window. A tool
 * the app wrapped in Spectra::tool() is already timed exactly, so it is not
 * recorded twice.
 *
 * Only calls made in this process are seen: results sent back from another
 * request or job have no previous call to time them from, and are skipped.
 *
 * Bound as scoped, so queue workers and Octane start every job and request
 * with nothing remembered.
 */
class ToolSpanRecorder
{
    /** Traces remembered at most, so a long-running command doesn't grow without end. */
    protected const MAX_TRACES = 100;

    /** Characters of an argument or result stored at most. */
    public const MAX_CONTENT_LENGTH = 10_000;

    /**
     * When the last AI call of each trace ended.
     *
     * @var array<string, CarbonInterface>
     */
    protected array $lastCallEndedAt = [];

    /**
     * Tool call ids already recorded per trace.
     *
     * @var array<string, array<string, true>>
     */
    protected array $recorded = [];

    /**
     * Names of the Spectra::tool() blocks run per trace since its last AI call.
     *
     * @var array<string, list<string>>
     */
    protected array $wrapped = [];

    /**
     * Tool calls of Responses API replies, by call id.
     *
     * @var array<string, array{name: ?string, arguments: mixed}>
     */
    protected array $responsesCalls = [];

    /**
     * Create a new tool span recorder instance.
     */
    public function __construct(
        protected SpanStack $spans,
        protected ToolCallParser $parser,
        protected PayloadSampler $sampler,
    ) {}

    /**
     * Record the tools whose results a finished AI call sent back, then
     * remember when it ended for the next call of its trace.
     */
    public function callFinished(RequestContext $context): void
    {
        if (! config('spectra.tracking.tool_spans', false) || blank($context->traceId)) {
            return;
        }

        $traceId = $context->traceId;

        rescue(fn () => $this->recordResults($context, $traceId));

        unset($this->lastCallEndedAt[$traceId]);
        $this->lastCallEndedAt[$traceId] = $context->getCompletedAt() ?? now();
        $this->wrapped[$traceId] = [];
        $this->forgetOldTraces();

        if (is_array($context->response)) {
            $this->responsesCalls = array_slice([...$this->responsesCalls, ...$this->parser->responsesCalls($context->response)], -500, preserve_keys: true);
        }
    }

    /**
     * Remember a Spectra::tool() block that ran, so the call that sends its
     * result back doesn't record it again.
     */
    public function toolWrapped(string $traceId, string $name, ?string $id): void
    {
        if ($id !== null) {
            $this->recorded[$traceId][$id] = true;
        } else {
            $this->wrapped[$traceId][] = $name;
        }

        $this->forgetOldTraces();
    }

    /**
     * Get the attributes a tool span stores: its name and id, and its arguments
     * and result when the trace's payloads are stored (`store_prompts` and
     * payload sampling), cut to MAX_CONTENT_LENGTH characters.
     *
     * @return array<string, mixed>
     */
    public function attributes(string $name, ?string $id, ?string $traceId, bool $failed, mixed $arguments, mixed $result, bool $hasResult): array
    {
        $attributes = ['gen_ai.tool.name' => $name];

        if ($id !== null) {
            $attributes['gen_ai.tool.call.id'] = $id;
        }

        if (! config('spectra.storage.store_prompts', true) || ! $this->sampler->keeps($traceId, $failed)) {
            return $attributes;
        }

        if ($arguments !== null && $arguments !== []) {
            $attributes['gen_ai.tool.call.arguments'] = $this->content($arguments);
        }

        if ($hasResult) {
            $attributes['gen_ai.tool.call.result'] = $this->content($result);
        }

        return $attributes;
    }

    /**
     * Record a span for each tool result the call sent back.
     */
    protected function recordResults(RequestContext $context, string $traceId): void
    {
        $previousEnd = $this->lastCallEndedAt[$traceId] ?? null;

        if ($previousEnd === null || $context->requestData === []) {
            return;
        }

        $startedAt = $context->getStartedAt();

        foreach ($this->parser->trailingResults($context->requestData) as $tool) {
            $known = $tool['id'] !== null ? ($this->responsesCalls[$tool['id']] ?? null) : null;
            $name = $tool['name'] ?? $known['name'] ?? 'tool';

            if ($this->alreadyRecorded($traceId, $tool['id'], $name)) {
                continue;
            }

            if ($tool['id'] !== null) {
                $this->recorded[$traceId][$tool['id']] = true;
            }

            $this->spans->recordFinished(
                name: $name,
                kind: SpanKind::Tool,
                traceId: $traceId,
                parentSpanId: $context->parentSpanId,
                startedAt: $previousEnd->lessThan($startedAt) ? $previousEnd : $startedAt,
                endedAt: $startedAt,
                attributes: $this->attributes($name, $tool['id'], $traceId, $tool['is_error'], $tool['arguments'] ?? $known['arguments'] ?? null, $tool['result'], hasResult: true),
                errorMessage: $tool['is_error'] ? 'The tool returned an error' : null,
            );
        }
    }

    /**
     * Determine if the tool was recorded already: by an earlier call of the
     * trace, or by a Spectra::tool() block of the same name.
     */
    protected function alreadyRecorded(string $traceId, ?string $id, string $name): bool
    {
        if ($id !== null && isset($this->recorded[$traceId][$id])) {
            return true;
        }

        $wrapped = $this->wrapped[$traceId] ?? [];
        $position = array_search($name, $wrapped, true);

        if ($position === false) {
            return false;
        }

        array_splice($wrapped, $position, 1);
        $this->wrapped[$traceId] = $wrapped;

        return true;
    }

    /**
     * Prepare a tool's argument or result for storage: arrays and scalars as
     * they are, objects as their array or string form, cut to MAX_CONTENT_LENGTH.
     */
    protected function content(mixed $value): mixed
    {
        $value = match (true) {
            $value instanceof Arrayable => $value->toArray(),
            $value instanceof JsonSerializable => $value->jsonSerialize(),
            $value instanceof Stringable => (string) $value,
            is_object($value) => get_debug_type($value),
            default => $value,
        };

        if (is_string($value)) {
            return Str::length($value) > self::MAX_CONTENT_LENGTH ? Str::substr($value, 0, self::MAX_CONTENT_LENGTH).'…' : $value;
        }

        if (is_array($value)) {
            $json = (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);

            return Str::length($json) > self::MAX_CONTENT_LENGTH ? Str::substr($json, 0, self::MAX_CONTENT_LENGTH).'…' : $value;
        }

        return $value;
    }

    /**
     * Keep the most recent traces only. Each list is trimmed on its own: a
     * trace whose tools ran here may have its next call somewhere else.
     */
    protected function forgetOldTraces(): void
    {
        while (count($this->lastCallEndedAt) > self::MAX_TRACES) {
            $oldest = array_key_first($this->lastCallEndedAt);
            unset($this->lastCallEndedAt[$oldest], $this->recorded[$oldest], $this->wrapped[$oldest]);
        }

        $this->recorded = array_slice($this->recorded, -self::MAX_TRACES, preserve_keys: true);
        $this->wrapped = array_slice($this->wrapped, -self::MAX_TRACES, preserve_keys: true);
    }
}
