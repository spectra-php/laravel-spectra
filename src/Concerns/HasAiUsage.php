<?php

declare(strict_types=1);

namespace Spectra\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The one trait users add to a model they track AI usage for: it bundles the
 * relationships, the usage statistics and the budget helpers, so a model
 * opts in with a single `use HasAiUsage;`.
 *
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
