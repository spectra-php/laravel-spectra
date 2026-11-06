<?php

declare(strict_types=1);

namespace Spectra\Support\Tracking;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraRequestPayload;

/**
 * Saves and loads a call's bodies in spectra_request_payloads.
 *
 * Until the migration that creates that table has run, bodies stay on the row
 * in spectra_requests, where rows saved before it keep them too.
 */
class PayloadStore
{
    /** The attributes that hold bodies. */
    public const ATTRIBUTES = ['request', 'response'];

    /**
     * Whether the table exists, checked once per process.
     */
    protected ?bool $hasTable = null;

    /**
     * Determine if bodies are stored in their own table.
     */
    public function isAvailable(): bool
    {
        return $this->hasTable ??= rescue(
            fn () => Schema::connection((new SpectraRequestPayload)->getConnectionName())->hasTable((new SpectraRequestPayload)->getTable()),
            false,
            report: false,
        );
    }

    /**
     * Save the bodies of a saved call, keeping a body the attributes don't name.
     * A call with no bodies, because of sampling or storage settings, has no row.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function save(SpectraRequest $request, array $attributes): void
    {
        $bodies = Arr::where(Arr::only($attributes, self::ATTRIBUTES), fn (mixed $body) => filled($body));

        if ($bodies === []) {
            return;
        }

        $encoded = Arr::map($bodies, fn (mixed $body) => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        SpectraRequestPayload::query()->upsert(
            [[
                'request' => null,
                'response' => null,
                ...$encoded,
                'request_id' => $request->getKey(),
                'created_at' => $request->created_at,
            ]],
            ['request_id'],
            array_keys($encoded),
        );
    }

    /**
     * Load the bodies of many calls at once, so reading them doesn't take a
     * query per call. Only calls without a body on their own row are looked up.
     *
     * @param  Collection<int, SpectraRequest>  $requests
     */
    public function loadInto(Collection $requests): void
    {
        if (! $this->isAvailable()) {
            return;
        }

        $missing = $requests->filter(fn (SpectraRequest $request) => $request->exists && ! $request->hasLoadedPayload());

        if ($missing->isEmpty()) {
            return;
        }

        $payloads = SpectraRequestPayload::query()->whereKey($missing->modelKeys())->get()->keyBy('request_id');

        foreach ($missing as $request) {
            $request->setPayload($payloads->get($request->getKey()));
        }
    }
}
