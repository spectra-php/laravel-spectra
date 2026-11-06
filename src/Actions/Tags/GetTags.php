<?php

declare(strict_types=1);

namespace Spectra\Actions\Tags;

use Illuminate\Support\Facades\DB;
use Spectra\Data\Responses\TagsResponse;
use Spectra\Data\Tag;
use Spectra\Models\SpectraTag;

/**
 * Every tag with how many requests carry it, most used first.
 */
class GetTags
{
    /**
     * Get every tag with its request count, most used first.
     */
    public function __invoke(): TagsResponse
    {
        return new TagsResponse(
            tags: Tag::fromCollection(
                // From the link table alone: joining each request adds nothing, as
                // deleting a request deletes its links.
                SpectraTag::query()
                    ->addSelect(['requests_count' => DB::connection((new SpectraTag)->getConnectionName())->table('spectra_requests_tags')
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('spectra_requests_tags.tag_id', 'spectra_tags.id')])
                    ->orderByDesc('requests_count')
                    ->get()
            ),
        );
    }
}
