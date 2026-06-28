<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Spectra\Spectra;
use Workbench\App\Models\User;

beforeEach(function () {
    config(['auth.providers.users.model' => User::class]);

    $this->user = User::create([
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
    ]);
});

it('accepts a user model', function () {
    $context = app(Spectra::class)
        ->forUser($this->user)
        ->startRequest('openai', 'gpt-4o');

    expect($context->trackableType)->toBe(User::class)
        ->and($context->trackableId)->toBe($this->user->id);
});

it('accepts a user id and loads the model', function () {
    $context = app(Spectra::class)
        ->forUser($this->user->id)
        ->startRequest('openai', 'gpt-4o');

    expect($context->trackableType)->toBe(User::class)
        ->and($context->trackableId)->toBe($this->user->id);
});

it('throws when the given user id does not exist', function () {
    app(Spectra::class)->forUser(999999);
})->throws(ModelNotFoundException::class);
