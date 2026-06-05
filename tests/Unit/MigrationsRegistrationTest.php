<?php

use Illuminate\Database\Migrations\Migrator;

it('does not auto register package schema migrations', function () {
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $registeredPaths = collect($migrator->paths())
        ->map(fn (string $path) => realpath($path) ?: $path);

    $packageSchemaPath = realpath(__DIR__.'/../../database/migrations');

    expect($registeredPaths)->not->toContain($packageSchemaPath);
});
