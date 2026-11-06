<?php

declare(strict_types=1);

namespace Spectra\Providers\Groq\Streaming;

use Illuminate\Support\Arr;
use Spectra\Providers\Groq\Handlers\ResponsesHandler;
use Spectra\Support\Tracking\StreamHandler;

/**
 * Groq Responses API stream (`response.*` server-sent events).
 *
 * Text comes from `response.output_text.delta`; usage, status, model and id
 * from the terminal `response.completed` (or `.incomplete` / `.failed`) event.
 */
class ResponsesStreaming extends StreamHandler
{
    protected const TERMINAL_EVENTS = ['response.completed', 'response.incomplete', 'response.failed'];

    /**
     * Create a new Groq Responses stream handler instance.
     */
    public function __construct(
        protected ResponsesHandler $handler,
    ) {}

    /**
     * Get the text delta of a `response.output_text.delta` event.
     *
     * @param  array<string, mixed>  $data
     */
    public function text(array $data): ?string
    {
        if (($data['type'] ?? null) !== 'response.output_text.delta') {
            return null;
        }

        return is_string($data['delta'] ?? null) ? $data['delta'] : null;
    }

    /**
     * Merge the terminal event's usage into the usage so far.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $currentUsage
     * @return array<string, mixed>
     */
    public function usage(array $data, array $currentUsage): array
    {
        $usage = $this->terminalResponse($data)['usage'] ?? null;

        if (! is_array($usage)) {
            return $currentUsage;
        }

        return array_merge($currentUsage, $this->handler->tokensFromUsage($usage)->toUsageArray());
    }

    /**
     * Get the terminal event's status as the finish reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function finishReason(array $data): ?string
    {
        $status = $this->terminalResponse($data)['status'] ?? null;

        return is_string($status) ? $status : null;
    }

    /**
     * Get the model and response id an event's response carries.
     *
     * @param  array<string, mixed>  $data
     * @return array{model?: string|null, id?: string|null}|null
     */
    public function model(array $data): ?array
    {
        $response = is_array($data['response'] ?? null) ? $data['response'] : [];

        $result = Arr::where([
            'model' => is_string($response['model'] ?? null) ? $response['model'] : null,
            'id' => is_string($response['id'] ?? null) ? $response['id'] : null,
        ], fn (?string $value) => (bool) $value);

        return $result !== [] ? $result : null;
    }

    /**
     * Get the response of a terminal event, or an empty array for other events.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function terminalResponse(array $data): array
    {
        if (! in_array($data['type'] ?? null, self::TERMINAL_EVENTS, true)) {
            return [];
        }

        return is_array($data['response'] ?? null) ? $data['response'] : [];
    }
}
