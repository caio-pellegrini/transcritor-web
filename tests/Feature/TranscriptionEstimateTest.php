<?php

use App\Actions\EstimateTranscription;
use App\Models\Transcription;
use App\Services\UsdBrlExchangeRateService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    config()->set('transcription.exchange_rate.fallback', 5.25);
});

function transcriptionForEstimate(string $provider, string $model, float $durationSeconds): Transcription
{
    return new Transcription([
        'provider' => $provider,
        'model' => $model,
        'duration_seconds' => $durationSeconds,
    ]);
}

test('openai estimate applies the configured one minute minimum', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([
            'USDBRL' => ['bid' => '5.1000', 'timestamp' => '1786377600'],
        ]),
    ]);

    $estimate = app(EstimateTranscription::class)->handle(
        transcriptionForEstimate('openai', 'gpt-4o-mini-transcribe', 30),
    );

    expect($estimate['cost_usd'])->toBe(0.003)
        ->and($estimate['cost_brl'])->toBe(0.0153)
        ->and($estimate['exchange_rate'])->toBe(5.1);
});

test('openai estimate scales by audio minutes after the minimum', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([
            'USDBRL' => ['bid' => '5.0000', 'timestamp' => '1786377600'],
        ]),
    ]);

    $estimate = app(EstimateTranscription::class)->handle(
        transcriptionForEstimate('openai', 'gpt-4o-transcribe', 150),
    );

    expect($estimate['cost_usd'])->toBe(0.015);
});

test('elevenlabs estimate uses hourly pricing without a minimum', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([
            'USDBRL' => ['bid' => '5.0000', 'timestamp' => '1786377600'],
        ]),
    ]);

    $estimate = app(EstimateTranscription::class)->handle(
        transcriptionForEstimate('elevenlabs', 'scribe_v2', 1800),
    );

    expect($estimate['cost_usd'])->toBe(0.11)
        ->and($estimate['cost_brl'])->toBe(0.55);
});

test('exchange rate is cached for 24 hours', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([
            'USDBRL' => ['bid' => '5.4321', 'timestamp' => '1786377600'],
        ]),
    ]);

    $service = app(UsdBrlExchangeRateService::class);

    expect($service->current())->toBe($service->current());
    Http::assertSentCount(1);
});

test('exchange rate failure uses configured fallback without breaking estimate', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([], 500),
    ]);

    $estimate = app(EstimateTranscription::class)->handle(
        transcriptionForEstimate('openai', 'gpt-4o-mini-transcribe', 60),
    );

    expect($estimate['cost_usd'])->toBe(0.003)
        ->and($estimate['cost_brl'])->toBe(0.01575)
        ->and($estimate['exchange_rate'])->toBe(5.25)
        ->and($estimate['exchange_rate_source'])->toBe('fallback')
        ->and($estimate['exchange_rate_quoted_at'])->toBeString();
});

test('estimate makes no requests to transcription providers', function () {
    Http::fake(function ($request) {
        expect($request->url())->toBe(config('transcription.exchange_rate.endpoint'));

        return Http::response([
            'USDBRL' => ['bid' => '5.0000', 'timestamp' => '1786377600'],
        ]);
    });

    app(EstimateTranscription::class)->handle(
        transcriptionForEstimate('elevenlabs', 'scribe_v2', 120),
    );

    Http::assertSentCount(1);
});
