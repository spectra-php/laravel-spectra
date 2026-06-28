<?php

declare(strict_types=1);

namespace Spectra\Http\Controllers\Api;

use Spectra\Actions\Config\GetProviders;
use Spectra\Data\Responses\ProvidersResponse;

class ProvidersController extends BaseApiController
{
    public function __invoke(GetProviders $action): ProvidersResponse
    {
        return $action();
    }
}
