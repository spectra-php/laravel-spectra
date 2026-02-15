<?php

namespace Spectra\Concerns;

/**
 * @phpstan-require-extends \Illuminate\Database\Eloquent\Model
 *
 * @phpstan-ignore trait.unused
 */
trait HasAiUsage
{
    use HasAiBudget;
    use HasAiRelationships;
    use HasAiUsageStats;
}
