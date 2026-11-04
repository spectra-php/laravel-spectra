<?php

declare(strict_types=1);

namespace Spectra\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * A tag attached to tracked requests (e.g. "feature:search"), used to filter
 * and group usage. Names are unique; the slug is derived from the name.
 *
 * @property string $id
 * @property string $name
 * @property-read string $slug
 * @property-read int|null $requests_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, SpectraRequest> $requests
 */
class SpectraTag extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $appends = ['slug'];

    protected $table = 'spectra_tags';

    protected $fillable = ['name'];

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * Get the requests that carry this tag.
     *
     * @return BelongsToMany<SpectraRequest, $this>
     */
    public function requests(): BelongsToMany
    {
        return $this->belongsToMany(
            SpectraRequest::class,
            'spectra_requests_tags',
            'tag_id',
            'request_id'
        );
    }

    /**
     * Get the tag with the given name, creating it when it doesn't exist.
     */
    public static function findOrCreateByName(string $name): self
    {
        return static::query()->firstOrCreate(['name' => $name]);
    }

    /**
     * Get the URL-safe slug derived from the tag's name.
     */
    public function getSlugAttribute(): string
    {
        return static::slugify($this->name);
    }

    /**
     * Get the URL-safe slug for a tag name.
     */
    public static function slugify(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * Get the tags with the given names, creating any that don't exist.
     *
     * @param  array<string>  $names
     * @return Collection<int, self>
     */
    public static function findOrCreateManyByName(array $names): Collection
    {
        return collect($names)->map(fn ($name) => static::findOrCreateByName($name));
    }
}
