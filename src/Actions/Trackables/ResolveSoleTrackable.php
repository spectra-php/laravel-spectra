<?php

declare(strict_types=1);

namespace Spectra\Actions\Trackables;

use Spectra\Models\SpectraRequest;
use Spectra\Support\Query\RequestAggregateQuery;

/**
 * The trackable every tracked call of a group (a trace, a conversation)
 * belongs to, with its name and email when it is a model. Null when the
 * calls have none, or more than one.
 */
class ResolveSoleTrackable
{
    public function __construct(
        private readonly ResolveTrackableMetadata $resolveTrackableMetadata,
    ) {}

    /**
     * @param  RequestAggregateQuery  $calls  scoped to the group, nothing selected yet
     * @return array<string, mixed>|null
     */
    public function __invoke(RequestAggregateQuery $calls): ?array
    {
        $trackables = $calls
            ->withTrackable()
            ->groupByColumns('trackable_type', 'trackable_id')
            ->limit(2)
            ->get();

        if ($trackables->count() !== 1) {
            return null;
        }

        /** @var SpectraRequest $trackable */
        $trackable = $trackables->first();
        $metadata = ($this->resolveTrackableMetadata)($trackables)["{$trackable->trackable_type}:{$trackable->trackable_id}"] ?? [];

        return [
            'type' => $trackable->trackable_type,
            'id' => $trackable->trackable_id,
            'name' => $metadata['name'] ?? null,
            'email' => $metadata['email'] ?? null,
        ];
    }
}
