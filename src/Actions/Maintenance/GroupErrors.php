<?php

declare(strict_types=1);

namespace Spectra\Actions\Maintenance;

use Spectra\Models\SpectraRequest;
use Spectra\Support\Errors\ErrorFingerprint;

/**
 * Fingerprints the failed calls saved before the error fingerprint migration
 * ran, so the Errors screen groups them too. New failures are fingerprinted
 * as they are saved.
 */
class GroupErrors
{
    /**
     * Create a new group errors action instance.
     */
    public function __construct(
        protected ErrorFingerprint $fingerprint,
    ) {}

    /**
     * Fingerprint every failed call without one, and return how many there were.
     */
    public function __invoke(): int
    {
        $grouped = 0;

        SpectraRequest::query()
            ->failed()
            ->whereNull('error_fingerprint')
            ->select(['id', 'provider', 'model', 'snapshot', 'status_code', 'error_type', 'error_message'])
            ->lazyById(500)
            ->each(function (SpectraRequest $request) use (&$grouped): void {
                SpectraRequest::query()->whereKey($request->id)->update([
                    'error_fingerprint' => $this->fingerprint->for($request->provider, $request->status_code, $request->error_type, $request->error_message, [$request->snapshot, $request->model]),
                ]);

                $grouped++;
            });

        return $grouped;
    }
}
