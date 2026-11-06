<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Playground;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spectra\Data\Responses\PlaygroundRunResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Models\SpectraRequest;
use Spectra\Support\Playground\PlaygroundAccess;
use Spectra\Support\Playground\PlaygroundClient;

/**
 * POST /api/playground/{id}/run: send the stored call to its provider again
 * with an edited `model` and `body`. The provider and endpoint come from the
 * stored call, never from the input. The body arrives as a JSON string, because
 * an app's TrimStrings and ConvertEmptyStringsToNull middleware would otherwise
 * change the text of the prompt. A provider's error is a result, not an HTTP
 * error, so the screen can show it next to the original.
 */
class RunPlaygroundCallController extends BaseApiController
{
    /**
     * Run the edited call and answer with its result.
     */
    public function __invoke(string $id, Request $request, PlaygroundClient $client): PlaygroundRunResponse
    {
        abort_unless(PlaygroundAccess::allowed(), 403);

        $input = $request->validate([
            'model' => ['required', 'string', 'max:255'],
            'body' => ['required', 'json'],
        ]);

        $body = json_decode($input['body'], true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($body) || array_is_list($body)) {
            throw ValidationException::withMessages(['body' => 'The body must be a JSON object.']);
        }

        $source = SpectraRequest::query()->findOrFail($id);
        $reason = $client->unavailableReason($source);

        abort_if($reason !== null, 422, (string) $reason);

        return $client->run($source->provider, (string) $source->endpoint, $input['model'], $body, $source->id);
    }
}
