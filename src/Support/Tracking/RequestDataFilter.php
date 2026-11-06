<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Removes system prompts and tool definitions from a raw request body before
 * it is stored, for the `store_system_prompts` and `store_tools` switches,
 * and the inline files (`data:` URIs) a request carries, which are
 * megabytes that say nothing about the call.
 *
 * The raw body is the only place Spectra stores either, so this is where the
 * switches take effect. Each provider puts them somewhere else; unknown keys
 * are left alone.
 */
class RequestDataFilter
{
    /**
     * Top-level keys that hold a system prompt: Anthropic and Ollama `system`,
     * OpenAI Responses and TTS `instructions`, Gemini `systemInstruction`,
     * Cohere v1 `preamble`, and `system_prompt` (Replicate and fal models).
     */
    protected const SYSTEM_KEYS = ['system', 'instructions', 'systemInstruction', 'system_instruction', 'preamble', 'system_prompt'];

    /** Message roles that carry a system prompt (OpenAI `developer` too). */
    protected const SYSTEM_ROLES = ['system', 'developer'];

    /** Tool definitions: `tools` everywhere, `functions` in the legacy OpenAI API. */
    protected const TOOL_KEYS = ['tools', 'functions'];

    /** What an inline file is stored as, like binary data in responses. */
    public const STRIPPED = '[stripped]';

    /**
     * Replace every inline file (a `data:` URI, such as a base64 image in a
     * vision prompt) anywhere in a request body with a placeholder, so a
     * stored request stays kilobytes however many images it sent.
     *
     * @param  array<mixed>  $data
     * @return array<mixed>
     */
    public function withoutInlineFiles(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->withoutInlineFiles($value);
            } elseif (is_string($value) && Str::startsWith($value, 'data:') && Str::contains($value, ';base64,')) {
                $data[$key] = self::STRIPPED;
            }
        }

        return $data;
    }

    /**
     * Remove system prompts from a request body, at the top level and in its messages or input.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withoutSystemPrompts(array $data): array
    {
        $data = $this->withoutKeys($data, self::SYSTEM_KEYS);

        foreach (['messages', 'input'] as $key) {
            if (! is_array($data[$key] ?? null)) {
                continue;
            }

            // A model's input object (Replicate, fal) holds its system prompt by key.
            $data[$key] = array_is_list($data[$key])
                ? $this->withoutSystemMessages($data[$key])
                : $this->withoutKeys($data[$key], self::SYSTEM_KEYS);
        }

        return $data;
    }

    /**
     * Remove tool and function definitions from a request body.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withoutTools(array $data): array
    {
        return $this->withoutKeys($data, self::TOOL_KEYS);
    }

    /**
     * Remove the system and developer messages from a message list.
     *
     * @param  array<int, mixed>  $messages
     * @return array<int, mixed>
     */
    protected function withoutSystemMessages(array $messages): array
    {
        return collect($messages)
            ->reject(fn (mixed $message): bool => is_array($message) && in_array($message['role'] ?? null, self::SYSTEM_ROLES, true))
            ->values()
            ->all();
    }

    /**
     * Remove the given keys from an array.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<array-key, mixed>
     */
    protected function withoutKeys(array $data, array $keys): array
    {
        return Arr::except($data, $keys);
    }
}
