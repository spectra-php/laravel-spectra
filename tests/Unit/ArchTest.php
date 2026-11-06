<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Spectra\Http\Controllers\Api\BaseApiController;
use Spectra\Support\Query\CostQueryBuilder;
use Spectra\Support\Query\RequestAggregateQuery;
use Spectra\Support\Query\UsageQueryBuilder;
use Spectra\Testing\FakeDashboards;

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

arch('shared dashboards share no code with the main dashboard')
    ->expect(['Spectra\Actions\Dashboards', 'Spectra\Http\Controllers\Dashboards', 'Spectra\Support\Dashboards', FakeDashboards::class])
    ->not->toUse([
        'Spectra\Http\Controllers\Api',
        'Spectra\Actions\Annotations',
        'Spectra\Actions\Config',
        'Spectra\Actions\Conversations',
        'Spectra\Actions\Costs',
        'Spectra\Actions\Errors',
        'Spectra\Actions\Evaluations',
        'Spectra\Actions\Maintenance',
        'Spectra\Actions\Media',
        'Spectra\Actions\Playground',
        'Spectra\Actions\Prompts',
        'Spectra\Actions\Requests',
        'Spectra\Actions\Scores',
        'Spectra\Actions\Stats',
        'Spectra\Actions\Tags',
        'Spectra\Actions\Traces',
        'Spectra\Actions\Trackables',
        RequestAggregateQuery::class,
        CostQueryBuilder::class,
        UsageQueryBuilder::class,
    ]);
