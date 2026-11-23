<?php

declare(strict_types=1);

namespace Spectra\Contracts;

/**
 * Marks a provider whose responses say what a request cost (OpenRouter's
 * `usage.cost`, xAI's `usage.cost_in_usd_ticks`, DeepInfra's
 * `usage.estimated_cost`). Spectra stores that figure instead of a catalog
 * estimate, so recalculating costs from the catalog leaves this provider's
 * requests as they were recorded.
 */
interface ReportsRequestCost {}
