<?php

use App\Models\Transcription;
use App\Services\TranscriptionModelAvailability;
use Tests\TestCase;

uses(TestCase::class);

function availabilityTranscription(
    string $provider,
    string $model,
    float $durationSeconds,
    int $sizeBytes,
): Transcription {
    return new Transcription([
        'provider' => $provider,
        'model' => $model,
        'duration_seconds' => $durationSeconds,
        'size_bytes' => $sizeBytes,
    ]);
}

test('all gpt transcription models use the conservative 25 minute limit', function (string $model) {
    $availability = app(TranscriptionModelAvailability::class)->forTranscription(
        availabilityTranscription('openai', $model, 1500.1, 10 * 1024 * 1024),
    );

    expect($availability['available'])->toBeFalse()
        ->and($availability['reason'])->toContain('25 minutos');
})->with([
    'GPT Transcribe' => 'gpt-transcribe',
]);

test('whisper transcode remains available at or above the quality floor', function () {
    $availability = app(TranscriptionModelAvailability::class)->forTranscription(
        availabilityTranscription('openai', 'whisper-1', 3 * 60 * 60, 30 * 1024 * 1024),
    );

    expect($availability['available'])->toBeTrue()
        ->and($availability['requires_transcode'])->toBeTrue()
        ->and($availability['bitrate_kbps'])->toBeGreaterThanOrEqual(16);
});

test('whisper is unavailable when fitting would require less than 16 kbps', function () {
    $availability = app(TranscriptionModelAvailability::class)->forTranscription(
        availabilityTranscription('openai', 'whisper-1', 4 * 60 * 60, 30 * 1024 * 1024),
    );

    expect($availability)->toMatchArray([
        'available' => false,
        'requires_transcode' => false,
        'bitrate_kbps' => null,
    ])->and($availability['reason'])->toContain('16 kbps');
});

test('elevenlabs accepts the full application range without transcode', function () {
    $transcription = availabilityTranscription(
        'elevenlabs',
        'scribe_v2',
        4 * 60 * 60,
        500 * 1024 * 1024,
    );
    expect(app(TranscriptionModelAvailability::class)->forTranscription($transcription))->toMatchArray([
        'available' => true,
        'requires_transcode' => false,
        'bitrate_kbps' => null,
    ]);
});
