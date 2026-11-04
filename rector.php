<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\Stmt\RemoveUnreachableStatementRector;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\Php82\Rector\Class_\ReadOnlyClassRector;
use RectorLaravel\Rector\ClassMethod\MakeModelAttributesAndScopesProtectedRector;
use RectorLaravel\Rector\ClassMethod\MigrateToSimplifiedAttributeRector;
use RectorLaravel\Rector\ClassMethod\ScopeNamedClassMethodToScopeAttributedClassMethodRector;
use RectorLaravel\Rector\Coalesce\ApplyDefaultInsteadOfNullCoalesceRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\MethodCall\ContainerBindConcreteWithClosureOnlyRector;
use RectorLaravel\Rector\MethodCall\ConvertEnumerableToArrayToAllRector;
use RectorLaravel\Rector\StaticCall\DispatchToHelperFunctionsRector;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

/*
 * Rector owns the mechanical rewrites (dead code, simpler conditionals,
 * Laravel helpers over plain PHP), as Pint owns formatting. Run it with
 * `composer refactor`; CI runs `vendor/bin/rector process --dry-run`.
 *
 * The sets stop at Laravel 12, the oldest version the package supports.
 * Every skipped rule says why.
 */
return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withCache(__DIR__.'/build/rector')
    ->withImportNames(importShortClasses: false, removeUnusedImports: true)
    ->withPhpSets(php82: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        earlyReturn: true,
    )
    ->withSets([
        LaravelLevelSetList::UP_TO_LARAVEL_120,
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
    ])
    ->withSkip([
        // config('x', $default) only applies when the key is missing; Spectra
        // has keys that are null on purpose (store_request_data), so `?? $default` stays.
        ApplyDefaultInsteadOfNullCoalesceRector::class,
        // ->all() keeps Arrayable items (DTOs) as objects where ->toArray() converts them.
        ConvertEnumerableToArrayToAllRector::class,
        // Public API: users extend these classes, call these methods and pass
        // constructor arguments by name, so none of them change in a minor release.
        ReadOnlyClassRector::class,
        ClassPropertyAssignToConstructorPromotionRector::class,
        MigrateToSimplifiedAttributeRector::class,
        MakeModelAttributesAndScopesProtectedRector::class,
        // The #[Scope] attribute is newer than Laravel 12.0, and scopeX() methods are public API.
        ScopeNamedClassMethodToScopeAttributedClassMethodRector::class,
        // Spectra resolves services with app() (.ai/rules/src.md); resolve() is the same call.
        AppToResolveRector::class,
        // `$x !== null` reads better than `$x instanceof Foo` for a nullable typed value.
        ExplicitNullableObjectInstanceRector::class,
        // A `yield` after a `throw` is unreachable but still makes the function a
        // generator, which stream tests rely on to fail on first read.
        RemoveUnreachableStatementRector::class,
        // A binding names its abstract (bind(Foo::class, …)) so users can see what to override.
        ContainerBindConcreteWithClosureOnlyRector::class,
        // Job::dispatch() is the documented style for the package's jobs.
        DispatchToHelperFunctionsRector::class,
    ]);
