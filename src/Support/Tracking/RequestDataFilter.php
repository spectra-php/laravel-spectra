<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

/**
 * Removes system prompts and tool definitions from a raw request body before
 * it is stored, for the `store_system_prompts` and `store_tools` switches.
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

    /**
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
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function withoutTools(array $data): array
    {
        return $this->withoutKeys($data, self::TOOL_KEYS);
    }

    /**
     * @param  array<int, mixed>  $messages
     * @return array<int, mixed>
     */
    protected function withoutSystemMessages(array $messages): array
    {
        return array_values(array_filter(
            $messages,
            fn (mixed $message) => ! (is_array($message) && in_array($message['role'] ?? null, self::SYSTEM_ROLES, true)),
        ));
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  array<int, string>  $keys
     * @return array<array-key, mixed>
     */
    protected function withoutKeys(array $data, array $keys): array
    {
        return array_diff_key($data, array_flip($keys));
    }
}
