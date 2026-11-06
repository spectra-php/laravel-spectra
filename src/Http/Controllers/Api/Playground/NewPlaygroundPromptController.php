<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api\Playground;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spectra\Actions\Playground\GetNewPlaygroundPrompt;
use Spectra\Data\Responses\PlaygroundCallResponse;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Playground\PlaygroundAccess;
use Spectra\Support\Playground\PlaygroundClient;

/**
 * GET /api/playground/new: a new prompt to edit, for the `provider` asked for
 * or the one the app used last.
 */
class NewPlaygroundPromptController extends BaseApiController
{
    /**
     * Get the new prompt with its starting body, models and providers.
     */
    public function __invoke(Request $request, PlaygroundClient $client, GetNewPlaygroundPrompt $getNewPlaygroundPrompt): PlaygroundCallResponse
    {
        abort_unless(PlaygroundAccess::allowed(), 403);

        $input = $request->validate([
            'provider' => ['nullable', 'string', Rule::in($client->providers())],
        ]);

        return $getNewPlaygroundPrompt($input['provider'] ?? null);
    }
}
