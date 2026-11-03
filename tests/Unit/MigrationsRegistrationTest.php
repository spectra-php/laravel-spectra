<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migrator;

/**
 * The package does not register its schema migrations on its own; apps
 * publish them.
 */
it('does not auto register package schema migrations', function () {
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $registeredPaths = collect($migrator->paths())
        ->map(fn (string $path) => realpath($path) ?: $path);

    $packageSchemaPath = realpath(__DIR__.'/../../database/migrations');

    expect($registeredPaths)->not->toContain($packageSchemaPath);
});
