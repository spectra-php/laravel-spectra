<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Playground;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spectra\Data\Responses\PlaygroundRunResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Playground\PlaygroundAccess;
use Spectra\Support\Playground\PlaygroundClient;

/**
 * POST /api/playground/run: send a new prompt to the chat endpoint of a built-in
 * `provider`, with a `model` and a `body`. The endpoint never comes from the
 * input. The body arrives as a JSON string, because an app's TrimStrings and
 * ConvertEmptyStringsToNull middleware would otherwise change the text of the
 * prompt. A provider's error is a result, not an HTTP error. `session` ties the
 * run to the others of the same new prompt.
 */
class RunNewPlaygroundPromptController extends BaseApiController
{
    /**
     * Run the new prompt and answer with its result.
     */
    public function __invoke(Request $request, PlaygroundClient $client): PlaygroundRunResponse
    {
        abort_unless(PlaygroundAccess::allowed(), 403);

        $input = $request->validate([
            'provider' => ['required', 'string', Rule::in($client->providers())],
            'model' => ['required', 'string', 'max:255'],
            'body' => ['required', 'json'],
            'session' => ['nullable', 'uuid'],
        ]);

        $body = json_decode($input['body'], true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($body) || array_is_list($body)) {
            throw ValidationException::withMessages(['body' => 'The body must be a JSON object.']);
        }

        $reason = $client->missingKeyReason($input['provider']);

        abort_if($reason !== null, 422, (string) $reason);

        return $client->run($input['provider'], $client->newPromptEndpoint($input['provider']), $input['model'], $body, sessionId: $input['session'] ?? null);
    }
}
