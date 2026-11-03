<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Spectra\Events\RequestTracked;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;

/**
 * Persisting a tracked request dispatches RequestTracked with the
 * transformed request data.
 */
it('dispatches RequestTracked with the transformed request once it is persisted', function () {
    Event::fake([RequestTracked::class]);
    $context = new RequestContext(['provider' => 'anthropic', 'model' => 'claude-sonnet-4-20250514']);
    $context->complete(['id' => 'resp-2'], ['prompt_tokens' => 50, 'completion_tokens' => 25]);

    app(RequestPersister::class)->persist($context);

    Event::assertDispatched(RequestTracked::class, fn (RequestTracked $event) => $event->request['provider'] === 'anthropic'
        && $event->request['model'] === 'Claude Sonnet 4'
        && $event->request['total_tokens'] === 75
        && isset($event->request['id'], $event->request['started_at'], $event->request['completed_at'])
        && $event->request['is_failed'] === false);
});
