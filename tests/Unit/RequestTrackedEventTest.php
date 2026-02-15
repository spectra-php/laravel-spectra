<?php

use Illuminate\Support\Facades\Event;
use Spectra\Events\RequestTracked;
use Spectra\Support\Tracking\RequestPersister;

it('dispatches RequestTracked event when a request is persisted', function () {
    Event::fake([RequestTracked::class]);

    config()->set('spectra.enabled', true);
    config()->set('spectra.storage.store_requests', true);

    $persister = app(RequestPersister::class);

    $context = new \Spectra\Support\Tracking\RequestContext([
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);
    $context->complete(['id' => 'resp-1'], ['prompt_tokens' => 10, 'completion_tokens' => 5]);

    $persister->persist($context);

    Event::assertDispatched(RequestTracked::class, function ($event) {
        return $event->request['provider'] === 'openai'
            && $event->request['model'] === 'GPT-4o'
            && $event->request['prompt_tokens'] === 10
            && $event->request['completion_tokens'] === 5;
    });
});

it('RequestTracked event contains transformed data', function () {
    Event::fake([RequestTracked::class]);

    config()->set('spectra.enabled', true);
    config()->set('spectra.storage.store_requests', true);

    $persister = app(RequestPersister::class);

    $context = new \Spectra\Support\Tracking\RequestContext([
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);
    $context->complete(['id' => 'resp-2'], ['prompt_tokens' => 50, 'completion_tokens' => 25]);

    $persister->persist($context);

    Event::assertDispatched(RequestTracked::class, function ($event) {
        return is_array($event->request)
            && isset($event->request['id'])
            && isset($event->request['total_tokens'])
            && $event->request['total_tokens'] === 75
            && isset($event->request['started_at'])
            && isset($event->request['completed_at'])
            && $event->request['is_failed'] === false;
    });
});
