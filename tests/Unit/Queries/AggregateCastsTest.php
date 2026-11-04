<?php

declare(strict_types=1);

/**
 * RequestAggregateQuery casts every aggregate alias it selects, so the
 * dashboard reads numbers even where the driver returns SUM and AVG as
 * strings (MySQL, PostgreSQL). SQLite returns numbers already, so these rows
 * are hydrated from strings the way those drivers hand them back.
 */

use Spectra\Support\Query\RequestAggregateQuery;

it('casts the dashboard aggregate aliases from driver strings to numbers', function () {
    $query = RequestAggregateQuery::make()
        ->countAs('requests')
        ->successfulCost()
        ->successfulLatency()
        ->tokenSum()
        ->usageSums()
        ->audioSums()
        ->mediaSums()
        ->toQuery();

    $row = $query->hydrate([[
        'requests' => '3',
        'cost' => '12.500000',
        'avg_latency' => '250.5000',
        'tokens_sum' => '1500',
        'images_sum' => '2',
        'videos_sum' => '1',
        'input_characters_sum' => '900',
        'duration_seconds_sum' => '12.5',
        'tts_characters_sum' => '400',
        'tts_duration_sum' => '7.25',
        'stt_duration_sum' => '5.25',
        'audio_duration_sum' => '12.5',
    ]])->first();

    expect($row->requests)->toBe(3)
        ->and($row->cost)->toBe(12.5)
        ->and($row->avg_latency)->toBe(250.5)
        ->and($row->tokens_sum)->toBe(1500)
        ->and($row->images_sum)->toBe(2)
        ->and($row->videos_sum)->toBe(1)
        ->and($row->input_characters_sum)->toBe(900)
        ->and($row->duration_seconds_sum)->toBe(12.5)
        ->and($row->tts_characters_sum)->toBe(400)
        ->and($row->tts_duration_sum)->toBe(7.25)
        ->and($row->stt_duration_sum)->toBe(5.25)
        ->and($row->audio_duration_sum)->toBe(12.5);
});

it('casts the trace and conversation totals from driver strings to numbers', function () {
    $query = RequestAggregateQuery::make()->conversationTotals()->toQuery();

    $row = $query->hydrate([[
        'request_count' => '4',
        'failed_count' => '1',
        'cost' => '3.000000',
        'tokens' => '800',
        'duration_ms' => '1234.5',
        'model_count' => '2',
        'turn_count' => '3',
    ]])->first();

    expect($row->request_count)->toBe(4)
        ->and($row->failed_count)->toBe(1)
        ->and($row->cost)->toBe(3.0)
        ->and($row->tokens)->toBe(800)
        ->and($row->duration_ms)->toBe(1234.5)
        ->and($row->model_count)->toBe(2)
        ->and($row->turn_count)->toBe(3);
});
