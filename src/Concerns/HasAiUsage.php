<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * @phpstan-require-extends Model
 *
 * @phpstan-ignore trait.unused
 */
trait HasAiUsage
{
    use HasAiBudget;
    use HasAiRelationships;
    use HasAiUsageStats;
}
