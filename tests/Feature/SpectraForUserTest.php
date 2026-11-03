<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spectra\Spectra;
use Workbench\App\Models\User;

/**
 * Spectra::forUser() attributes the next request to a user given as a model
 * or an id, and throws for an id that does not exist.
 */
beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
});

it('attributes the request to a user given as a model', function () {
    $context = app(Spectra::class)
        ->forUser($this->user)
        ->startRequest('openai', 'gpt-4o');

    expect($context->trackableType)->toBe(User::class)
        ->and($context->trackableId)->toBe($this->user->id);
});

it('attributes the request to a user given by id', function () {
    $context = app(Spectra::class)
        ->forUser($this->user->id)
        ->startRequest('openai', 'gpt-4o');

    expect($context->trackableType)->toBe(User::class)
        ->and($context->trackableId)->toBe($this->user->id);
});

it('throws when the given user id does not exist', function () {
    expect(fn () => app(Spectra::class)->forUser(999999))->toThrow(ModelNotFoundException::class);
});
