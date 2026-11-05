<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Spectra\Http\Controllers\Api\BaseApiController;

/**
 * Structural rules for the package source: readonly DTOs, invokable actions,
 * controllers on the base controller, interfaces for contracts and no debug
 * calls.
 */
arch('data transfer objects are readonly')
    ->expect('Spectra\Data')
    ->toBeReadonly();

arch('everything in Spectra\Enums is an enum')
    ->expect('Spectra\Enums')
    ->toBeEnums();

arch('models extend eloquent model')
    ->expect('Spectra\Models')
    ->toExtend(Model::class);

arch('actions are invokable')
    ->expect('Spectra\Actions')
    ->toHaveMethod('__invoke');

arch('controllers extend base controller')
    ->expect('Spectra\Http\Controllers\Api')
    ->toExtend(BaseApiController::class);

arch('contracts are interfaces')
    ->expect('Spectra\Contracts')
    ->toBeInterfaces();

arch('no debugging statements in source code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
