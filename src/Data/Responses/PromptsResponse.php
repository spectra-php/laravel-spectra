<?php

declare(strict_types=1);

namespace Spectra\Data\Responses;

use Illuminate\Support\Collection;
use Spectra\Data\DataTransferObject;
use Spectra\Data\PromptVersion;

/**
 * The Prompts screen: every prompt template version used in the period.
 */
readonly class PromptsResponse extends DataTransferObject
{
    /**
     * Create a new prompts response instance.
     *
     * @param  Collection<int, PromptVersion>  $versions
     */
    public function __construct(
        public Collection $versions,
    ) {}
}
