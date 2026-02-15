<?php

namespace Spectra\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $name
 * @property-read string $slug
 * @property-read int|null $requests_count
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \Spectra\Models\SpectraRequest> $requests
 */
class SpectraTag extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $appends = ['slug'];

    protected $table = 'spectra_tags';

    protected $fillable = ['name'];

    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany<\Spectra\Models\SpectraRequest, $this>
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

    public static function findOrCreateByName(string $name): self
    {
        return static::firstOrCreate(['name' => $name]);
    }

    public function getSlugAttribute(): string
    {
        return static::slugify($this->name);
    }

    public static function slugify(string $name): string
    {
        return Str::slug($name);
    }

    /**
     * @param  array<string>  $names
     * @return Collection<int, self>
     */
    public static function findOrCreateManyByName(array $names): Collection
    {
        return collect($names)->map(fn ($name) => static::findOrCreateByName($name));
    }
}
