<?php

arch('data transfer objects are readonly')
    ->expect('Spectra\Data')
    ->toBeReadonly();

arch('enums are enums')
    ->expect('Spectra\Enums')
    ->toBeEnums();

arch('models extend eloquent model')
    ->expect('Spectra\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model');

arch('actions are invokable')
    ->expect('Spectra\Actions')
    ->toHaveMethod('__invoke');

arch('queries are invokable')
    ->expect('Spectra\Queries')
    ->toHaveMethod('__invoke');

arch('controllers extend base controller')
    ->expect('Spectra\Http\Controllers\Api')
    ->toExtend('Spectra\Http\Controllers\Api\BaseApiController');

arch('contracts are interfaces')
    ->expect('Spectra\Contracts')
    ->toBeInterfaces();

arch('no debugging statements in source code')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r'])
    ->not->toBeUsed();
