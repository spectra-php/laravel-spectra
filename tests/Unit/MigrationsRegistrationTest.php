<?php

it('does not auto register package schema migrations', function () {
    /** @var \Illuminate\Database\Migrations\Migrator $migrator */
    $migrator = app('migrator');

    $registeredPaths = collect($migrator->paths())
        ->map(fn (string $path) => realpath($path) ?: $path);

    $packageSchemaPath = realpath(__DIR__.'/../../database/migrations');

    expect($registeredPaths)->not->toContain($packageSchemaPath);
});
