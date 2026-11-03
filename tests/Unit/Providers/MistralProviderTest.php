<?php

declare(strict_types=1);

use Spectra\Providers\Mistral\Handlers\ChatHandler;

/**
 * The Mistral chat handler reads cache hits from each usage field Mistral
 * has reported them in.
 */
it('reads cache hits from every usage field Mistral uses', function () {
    $handler = new ChatHandler;

    expect($handler->tokensFromUsage(['prompt_tokens' => 100, 'completion_tokens' => 1, 'prompt_tokens_details' => ['cached_tokens' => 40]])->cachedTokens)->toBe(40)
        ->and($handler->tokensFromUsage(['prompt_tokens' => 100, 'completion_tokens' => 1, 'prompt_token_details' => ['cached_tokens' => 30]])->cachedTokens)->toBe(30)
        ->and($handler->tokensFromUsage(['prompt_tokens' => 100, 'completion_tokens' => 1, 'num_cached_tokens' => 20])->cachedTokens)->toBe(20);
});
