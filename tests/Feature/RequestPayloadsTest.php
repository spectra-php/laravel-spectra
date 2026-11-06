<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Sleep;
use Spectra\Actions\Conversations\GetConversationDetails;
use Spectra\Actions\Maintenance\PruneEntries;
use Spectra\Http\Controllers\Api\ShowRequestController;
use Spectra\Models\SpectraRequest;
use Spectra\Models\SpectraRequestPayload;
use Spectra\Support\Tracking\PayloadStore;
use Spectra\Support\Tracking\RequestContext;
use Spectra\Support\Tracking\RequestPersister;
use Spectra\Support\Tracking\RequestWriter;

/**
 * Request and response bodies live apart from the calls, so a scan of the calls
 * doesn't read them and they can be pruned sooner. Every reader still finds
 * them where they are, including on rows saved before the table existed.
 */
function trackedWithBodies(array $attributes = []): SpectraRequest
{
    $context = new RequestContext([
        'provider' => 'openai',
        'model' => 'gpt-4o',
        'model_type' => 'text',
        'request_data' => ['messages' => [['role' => 'user', 'content' => 'Hello']]],
        ...$attributes,
    ]);
    $context->complete(['id' => 'x', 'choices' => [['message' => ['content' => 'Hi']]]], ['prompt_tokens' => 1, 'completion_tokens' => 1]);

    return app(RequestPersister::class)->persist($context);
}

it('saves the bodies apart from the call and reads them back on the call', function () {
    $saved = trackedWithBodies();

    $request = SpectraRequest::query()->sole();

    expect(DB::table('spectra_requests')->value('request'))->toBeNull()
        ->and(DB::table('spectra_requests')->value('response'))->toBeNull()
        ->and(SpectraRequestPayload::query()->sole()->request_id)->toBe($saved->id)
        ->and($request->request['messages'][0]['content'])->toBe('Hello')
        ->and($request->response['id'])->toBe('x');
});

it('shows the bodies on the request page', function () {
    $saved = trackedWithBodies();

    $details = app()->call([app(ShowRequestController::class), '__invoke'], ['request' => new Request, 'id' => $saved->id])->toArray();

    expect($details['request']['messages'][0]['content'])->toBe('Hello')
        ->and($details['response']['id'])->toBe('x');
});

it('leaves the bodies out of a call\'s array unless asked', function () {
    trackedWithBodies();

    expect(SpectraRequest::query()->sole()->toArray())->not->toHaveKey('request');
});

it('reads a row saved before the table existed from the row itself', function () {
    $old = SpectraRequest::factory()->create(['request' => ['old' => true], 'response' => ['old' => 'answer']]);

    $request = SpectraRequest::query()->findOrFail($old->id);

    expect($request->request)->toEqual(['old' => true])
        ->and($request->response)->toEqual(['old' => 'answer'])
        ->and(SpectraRequestPayload::query()->count())->toBe(0);
});

it('does not write loaded bodies back onto the row when the row is saved', function () {
    trackedWithBodies();
    $request = SpectraRequest::query()->sole();
    expect($request->request)->not->toBeNull();

    $request->status_code = 201;
    $request->save();

    expect(DB::table('spectra_requests')->value('request'))->toBeNull()
        ->and($request->getDirty())->toBe([]);
});

it('keeps the first body when a poll of an async job carries none', function () {
    $writer = app(RequestWriter::class);
    $base = ['provider' => 'replicate', 'model' => 'flux', 'response_id' => 'job-1', 'status_code' => 200];

    $writer->write($base + ['request' => ['prompt' => 'A cat'], 'response' => ['status' => 'starting']]);
    $writer->write($base + ['request' => null, 'response' => ['status' => 'succeeded']]);

    $request = SpectraRequest::query()->sole();

    expect($request->request)->toEqual(['prompt' => 'A cat'])
        ->and($request->response)->toEqual(['status' => 'succeeded']);
});

it('replaces a body on a row from before the table instead of hiding the new one behind it', function () {
    $old = SpectraRequest::factory()->create(['response_id' => 'job-2', 'request' => ['prompt' => 'Old'], 'response' => ['status' => 'starting']]);

    app(RequestWriter::class)->write(['provider' => 'replicate', 'model' => 'flux', 'response_id' => 'job-2', 'status_code' => 200, 'response' => ['status' => 'succeeded']]);

    $request = SpectraRequest::query()->findOrFail($old->id);

    expect($request->response)->toEqual(['status' => 'succeeded'])
        ->and($request->request)->toEqual(['prompt' => 'Old']);
});

it('reads a conversation\'s bodies in one query', function () {
    trackedWithBodies(['conversation_id' => 'chat-1']);
    trackedWithBodies(['conversation_id' => 'chat-1']);
    $payloadQueries = 0;
    DB::listen(function ($query) use (&$payloadQueries) {
        if (str_contains($query->sql, 'spectra_request_payloads')) {
            $payloadQueries++;
        }
    });

    $details = app(GetConversationDetails::class)('chat-1');

    expect($details->request_count)->toBe(2)
        ->and($payloadQueries)->toBe(1);
});

it('stores the bodies on the row until the migration has run', function () {
    Schema::drop('spectra_request_payloads');
    app()->forgetInstance(PayloadStore::class);

    trackedWithBodies();

    $request = SpectraRequest::query()->sole();

    expect(DB::table('spectra_requests')->value('request'))->not->toBeNull()
        ->and($request->request['messages'][0]['content'])->toBe('Hello');
});

describe('pruning', function () {
    it('deletes a pruned call\'s bodies and links', function () {
        $this->travelTo(now()->subDays(3));
        $old = trackedWithBodies();
        $old->attachTags(['old']);
        $this->travelBack();
        $kept = trackedWithBodies();

        app(PruneEntries::class)(hours: 24);

        expect(SpectraRequestPayload::query()->pluck('request_id')->all())->toBe([$kept->id])
            ->and(DB::table('spectra_requests_tags')->count())->toBe(0);
    });

    it('deletes old bodies sooner than their calls when asked', function () {
        $this->travelTo(now()->subDays(3));
        trackedWithBodies();
        SpectraRequest::factory()->create(['request' => ['old' => true], 'response' => ['old' => true], 'created_at' => now()]);
        $this->travelBack();
        $recent = trackedWithBodies();

        $result = app(PruneEntries::class)(hours: 24 * 30, payloadHours: 24);

        expect(SpectraRequest::query()->count())->toBe(3)
            ->and($result->requests)->toBe(0)
            ->and($result->payloads)->toBe(2)
            ->and(SpectraRequestPayload::query()->pluck('request_id')->all())->toBe([$recent->id])
            ->and(DB::table('spectra_requests')->whereNotNull('request')->count())->toBe(0);
    });

    it('waits between chunks when asked', function () {
        Sleep::fake();
        SpectraRequest::factory()->count(3)->create(['created_at' => now()->subDays(3)]);

        app(PruneEntries::class)(hours: 24, chunk: 2, pauseMs: 25);

        Sleep::assertSleptTimes(2);
    });
});
