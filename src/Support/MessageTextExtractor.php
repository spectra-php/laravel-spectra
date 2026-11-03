<?php

declare(strict_types=1);

namespace Spectra\Support;

use Spectra\Models\SpectraRequest;

/**
 * The user's message and the model's reply of a stored call, as plain text,
 * for the conversation transcript.
 *
 * The stored request is the provider's own body, so the prompt is read from
 * the shapes chat APIs share: a `messages` list (OpenAI, Anthropic, Mistral,
 * Groq, xAI, OpenRouter, Ollama, Cohere v2), OpenAI Responses `input`, Gemini
 * `contents`, and a bare `message` or `prompt` string. The reply comes from
 * the provider's handler, or from the accumulated text of a streamed call.
 * Either is null when the payload wasn't stored or holds no text.
 */
class MessageTextExtractor
{
    /** Part types that carry text in a message's content list. */
    protected const TEXT_PART_TYPES = ['text', 'input_text', 'output_text'];

    public function __construct(
        protected ProviderRegistry $providers,
    ) {}

    /**
     * The text of the last user message in a stored request body.
     *
     * @param  mixed  $request  the decoded `request` column
     */
    public function prompt(mixed $request): ?string
    {
        if (! is_array($request)) {
            return null;
        }

        if (is_array($request['messages'] ?? null)) {
            return $this->text($this->lastUserMessage($request['messages'])['content'] ?? null);
        }

        $input = $request['input'] ?? null;

        if (is_array($input)) {
            return $this->text($this->lastUserMessage($input)['content'] ?? null);
        }

        if (is_array($request['contents'] ?? null)) {
            return $this->text($this->lastUserMessage($request['contents'], roleOptional: true)['parts'] ?? null);
        }

        foreach ([$input, $request['message'] ?? null, $request['prompt'] ?? null] as $candidate) {
            if (is_string($candidate) && filled($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The model's reply text of a stored call.
     */
    public function reply(SpectraRequest $call): ?string
    {
        $response = $call->response;

        if (! is_array($response)) {
            return null;
        }

        // A streamed call stores the text it accumulated, not the provider's chunks.
        if (($response['streaming'] ?? false) === true) {
            return filled($response['content'] ?? null) ? (string) $response['content'] : null;
        }

        $handler = $this->providers->provider($call->provider)?->resolveHandler((string) $call->endpoint, $response);
        $reply = $handler?->extractResponse($response);

        return filled($reply) ? $reply : null;
    }

    /**
     * The last message a user sent. Gemini may leave out the role of a
     * user's turn, so with $roleOptional a message without one counts.
     *
     * @param  array<mixed>  $messages
     * @return array<string, mixed>|null
     */
    protected function lastUserMessage(array $messages, bool $roleOptional = false): ?array
    {
        foreach (array_reverse($messages) as $message) {
            if (! is_array($message)) {
                continue;
            }

            $role = $message['role'] ?? null;

            if ($role === 'user' || ($roleOptional && $role === null)) {
                return $message;
            }
        }

        return null;
    }

    /**
     * Text from a message's content: a string, or the text parts of a list
     * (images, audio and tool results are left out).
     */
    protected function text(mixed $content): ?string
    {
        if (is_string($content)) {
            return filled($content) ? $content : null;
        }

        if (! is_array($content)) {
            return null;
        }

        $texts = [];

        foreach ($content as $part) {
            if (is_string($part) && filled($part)) {
                $texts[] = $part;

                continue;
            }

            $type = is_array($part) ? ($part['type'] ?? null) : null;
            $text = is_array($part) ? ($part['text'] ?? null) : null;

            if (($type === null || in_array($type, self::TEXT_PART_TYPES, true)) && is_string($text) && filled($text)) {
                $texts[] = $text;
            }
        }

        return $texts === [] ? null : implode("\n\n", $texts);
    }
}
