<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Reads the tool results an AI request sends back to the model, with the
 * tool call each one answers: its id, name and arguments.
 *
 * Only the results the request ends with count: the tools the app ran since
 * the model's last reply. Results further up the history were answered by
 * an earlier call. Four body shapes are understood:
 *
 * - Chat Completions (OpenAI and the APIs that copy it: Groq, Mistral, xAI,
 *   OpenRouter, Cohere v2, Ollama): `role: tool` messages after an assistant
 *   message with `tool_calls`.
 * - Responses (OpenAI): `function_call_output` items after `function_call` items.
 * - Messages (Anthropic): `tool_result` blocks in the last user message,
 *   after an assistant message with `tool_use` blocks.
 * - Gemini: `functionResponse` parts in the last content, after a model
 *   content with `functionCall` parts.
 *
 * Without an id, a result is matched to the call at the same position.
 */
class ToolCallParser
{
    /**
     * Get the tool results at the end of the request body.
     *
     * @param  array<array-key, mixed>  $body
     * @return list<array{id: ?string, name: ?string, arguments: mixed, result: mixed, is_error: bool}>
     */
    public function trailingResults(array $body): array
    {
        return match (true) {
            is_array($body['messages'] ?? null) => $this->fromMessages(array_values($body['messages'])),
            is_array($body['input'] ?? null) => $this->fromResponsesInput(array_values($body['input'])),
            is_array($body['contents'] ?? null) => $this->fromGeminiContents(array_values($body['contents'])),
            default => [],
        };
    }

    /**
     * Get the tool calls the model asked for in a Responses API reply, by call id.
     *
     * A request that continues the reply with `previous_response_id` sends
     * only the results, so the names come from here.
     *
     * @param  array<array-key, mixed>  $response
     * @return array<string, array{name: ?string, arguments: mixed}>
     */
    public function responsesCalls(array $response): array
    {
        $calls = [];

        foreach ((array) ($response['output'] ?? []) as $item) {
            if (is_array($item) && in_array($item['type'] ?? null, ['function_call', 'custom_tool_call'], true) && is_string($item['call_id'] ?? null)) {
                $calls[$item['call_id']] = ['name' => $this->string($item['name'] ?? null), 'arguments' => $this->decode($item['arguments'] ?? $item['input'] ?? null)];
            }
        }

        return $calls;
    }

    /**
     * Read the tool results from Chat Completions `role: tool` messages, or Anthropic `tool_result` blocks.
     *
     * @param  list<mixed>  $messages
     * @return list<array{id: ?string, name: ?string, arguments: mixed, result: mixed, is_error: bool}>
     */
    protected function fromMessages(array $messages): array
    {
        $last = end($messages);

        if (is_array($last) && ($last['role'] ?? null) === 'user' && is_array($last['content'] ?? null)) {
            return $this->fromAnthropic($messages);
        }

        $results = [];
        $index = count($messages) - 1;

        while ($index >= 0 && is_array($messages[$index]) && ($messages[$index]['role'] ?? null) === 'tool') {
            array_unshift($results, $messages[$index]);
            $index--;
        }

        if ($results === []) {
            return [];
        }

        $calls = collect((array) (Arr::get((array) ($messages[$index] ?? []), 'tool_calls') ?? []))
            ->filter(fn (mixed $call): bool => is_array($call))
            ->map(fn (array $call): array => [
                'id' => $this->string($call['id'] ?? null),
                'name' => $this->string(Arr::get($call, 'function.name')),
                'arguments' => $this->decode(Arr::get($call, 'function.arguments')),
            ])
            ->values()
            ->all();

        return $this->match(collect($results)->map(fn (array $message): array => [
            'id' => $this->string($message['tool_call_id'] ?? null),
            'name' => $this->string($message['tool_name'] ?? $message['name'] ?? null),
            'result' => $this->decode($message['content'] ?? null),
            'is_error' => false,
        ])->all(), $calls);
    }

    /**
     * Read the tool results from Anthropic `tool_result` blocks in the last user message.
     *
     * @param  list<mixed>  $messages
     * @return list<array{id: ?string, name: ?string, arguments: mixed, result: mixed, is_error: bool}>
     */
    protected function fromAnthropic(array $messages): array
    {
        $blocks = (array) Arr::get((array) end($messages), 'content', []);
        $results = collect($blocks)
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_result')
            ->map(fn (array $block): array => [
                'id' => $this->string($block['tool_use_id'] ?? null),
                'name' => null,
                'result' => $this->anthropicContent($block['content'] ?? null),
                'is_error' => (bool) ($block['is_error'] ?? false),
            ])
            ->values()
            ->all();

        if ($results === []) {
            return [];
        }

        $previous = $messages[count($messages) - 2] ?? [];
        $calls = collect((array) Arr::get((array) $previous, 'content', []))
            ->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'tool_use')
            ->map(fn (array $block): array => [
                'id' => $this->string($block['id'] ?? null),
                'name' => $this->string($block['name'] ?? null),
                'arguments' => $block['input'] ?? null,
            ])
            ->values()
            ->all();

        return $this->match($results, $calls);
    }

    /**
     * Read the tool results from Responses API `function_call_output` items at the end of the input.
     *
     * @param  list<mixed>  $input
     * @return list<array{id: ?string, name: ?string, arguments: mixed, result: mixed, is_error: bool}>
     */
    protected function fromResponsesInput(array $input): array
    {
        $results = [];
        $index = count($input) - 1;

        while ($index >= 0 && is_array($input[$index]) && in_array($input[$index]['type'] ?? null, ['function_call_output', 'custom_tool_call_output'], true)) {
            array_unshift($results, $input[$index]);
            $index--;
        }

        if ($results === []) {
            return [];
        }

        $calls = collect($input)
            ->filter(fn (mixed $item): bool => is_array($item) && in_array($item['type'] ?? null, ['function_call', 'custom_tool_call'], true))
            ->map(fn (array $item): array => [
                'id' => $this->string($item['call_id'] ?? null),
                'name' => $this->string($item['name'] ?? null),
                'arguments' => $this->decode($item['arguments'] ?? $item['input'] ?? null),
            ])
            ->values()
            ->all();

        return $this->match(collect($results)->map(fn (array $item): array => [
            'id' => $this->string($item['call_id'] ?? null),
            'name' => null,
            'result' => $this->decode($item['output'] ?? null),
            'is_error' => false,
        ])->all(), $calls, byPosition: false);
    }

    /**
     * Read the tool results from Gemini `functionResponse` parts in the last content.
     *
     * @param  list<mixed>  $contents
     * @return list<array{id: ?string, name: ?string, arguments: mixed, result: mixed, is_error: bool}>
     */
    protected function fromGeminiContents(array $contents): array
    {
        $parts = (array) Arr::get((array) end($contents), 'parts', []);
        $results = collect($parts)
            ->filter(fn (mixed $part): bool => is_array($part) && is_array($part['functionResponse'] ?? null))
            ->map(fn (array $part): array => [
                'id' => $this->string(Arr::get($part, 'functionResponse.id')),
                'name' => $this->string(Arr::get($part, 'functionResponse.name')),
                'result' => Arr::get($part, 'functionResponse.response'),
                'is_error' => false,
            ])
            ->values()
            ->all();

        if ($results === []) {
            return [];
        }

        $previous = $contents[count($contents) - 2] ?? [];
        $calls = collect((array) Arr::get((array) $previous, 'parts', []))
            ->filter(fn (mixed $part): bool => is_array($part) && is_array($part['functionCall'] ?? null))
            ->map(fn (array $part): array => [
                'id' => $this->string(Arr::get($part, 'functionCall.id')),
                'name' => $this->string(Arr::get($part, 'functionCall.name')),
                'arguments' => Arr::get($part, 'functionCall.args'),
            ])
            ->values()
            ->all();

        return $this->match($results, $calls);
    }

    /**
     * Pair each result with its call: by id, else by name in order, else by
     * position.
     *
     * @param  array<int, array{id: ?string, name: ?string, result: mixed, is_error: bool}>  $results
     * @param  array<int, array{id: ?string, name: ?string, arguments: mixed}>  $calls
     * @return list<array{id: ?string, name: ?string, arguments: mixed, result: mixed, is_error: bool}>
     */
    protected function match(array $results, array $calls, bool $byPosition = true): array
    {
        $paired = [];
        $unused = $calls;

        foreach (array_values($results) as $position => $result) {
            $key = $this->findCall($unused, $result, $byPosition ? $position : null);
            $call = $key === null ? null : $unused[$key];

            if ($key !== null) {
                unset($unused[$key]);
            }

            $paired[] = [
                'id' => $result['id'] ?? $call['id'] ?? null,
                'name' => $call['name'] ?? $result['name'],
                'arguments' => $call['arguments'] ?? null,
                'result' => $result['result'],
                'is_error' => $result['is_error'],
            ];
        }

        return $paired;
    }

    /**
     * Find the key of the call a result answers, among the calls not yet paired.
     *
     * @param  array<int, array{id: ?string, name: ?string, arguments: mixed}>  $calls
     * @param  array{id: ?string, name: ?string, result: mixed, is_error: bool}  $result
     */
    protected function findCall(array $calls, array $result, ?int $position): ?int
    {
        if ($result['id'] !== null) {
            foreach ($calls as $key => $call) {
                if ($call['id'] === $result['id']) {
                    return $key;
                }
            }

            // An id no call has: the call was in an earlier reply, not this request.
            return null;
        }

        if ($result['name'] !== null) {
            foreach ($calls as $key => $call) {
                if ($call['name'] === $result['name']) {
                    return $key;
                }
            }
        }

        return $position !== null && isset($calls[$position]) ? $position : null;
    }

    /**
     * Get the content of an Anthropic tool result: a string, or blocks whose text is joined.
     */
    protected function anthropicContent(mixed $content): mixed
    {
        if (! is_array($content)) {
            return $this->decode($content);
        }

        $texts = collect($content)->filter(fn (mixed $block): bool => is_array($block) && ($block['type'] ?? null) === 'text');

        return $texts->count() === count($content)
            ? $this->decode($texts->pluck('text')->implode("\n"))
            : $content;
    }

    /**
     * Decode a JSON string to its value, returning any other value as it is.
     */
    protected function decode(mixed $value): mixed
    {
        if (! is_string($value) || ! Str::isJson($value)) {
            return $value;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Get the value when it is a non-empty string, or null.
     */
    protected function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
