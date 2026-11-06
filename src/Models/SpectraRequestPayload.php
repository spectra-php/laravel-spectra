<?php

declare(strict_types=1);

namespace Spectra\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * The request and response bodies of one tracked call, apart from its row in
 * spectra_requests so that row stays small. SpectraRequest reads them on its
 * `request` and `response` attributes.
 *
 * @property string $request_id
 * @property string|null $request
 * @property string|null $response
 * @property CarbonInterface|null $created_at
 */
class SpectraRequestPayload extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'request_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * Get the database connection Spectra's tables live on.
     */
    public function getConnectionName(): ?string
    {
        return config('spectra.storage.connection');
    }
}
