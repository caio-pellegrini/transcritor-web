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
    'GPT-4o Mini' => 'gpt-4o-mini-transcribe',
    'GPT Transcribe' => 'gpt-transcribe',
    'GPT-4o' => 'gpt-4o-transcribe',
]);

test('long openai diarization explicitly redirects the user to elevenlabs', function () {
    $availability = app(TranscriptionModelAvailability::class)->forTranscription(
        availabilityTranscription(
            'openai',
            'gpt-4o-transcribe-diarize',
            1800,
            10 * 1024 * 1024,
        ),
    );

    expect($availability)->toMatchArray([
        'available' => false,
        'reason' => 'Acima de 25 minutos, use a diarização da ElevenLabs.',
    ]);
});

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

test('elevenlabs accepts the full application range without transcode and is recommended for long files', function () {
    $transcription = availabilityTranscription(
        'elevenlabs',
        'scribe_v2',
        4 * 60 * 60,
        500 * 1024 * 1024,
    );
    $service = app(TranscriptionModelAvailability::class);

    expect($service->forTranscription($transcription))->toMatchArray([
        'available' => true,
        'requires_transcode' => false,
        'bitrate_kbps' => null,
    ])->and($service->recommendedProviderFor($transcription))->toBe('elevenlabs');
});

test('openai mini is recommended only when a short file needs no quality-reducing transcode', function () {
    $service = app(TranscriptionModelAvailability::class);

    expect($service->recommendedProviderFor(
        availabilityTranscription('openai', 'gpt-4o-mini-transcribe', 600, 10 * 1024 * 1024),
    ))->toBe('openai')
        ->and($service->recommendedProviderFor(
            availabilityTranscription('openai', 'gpt-4o-mini-transcribe', 600, 30 * 1024 * 1024),
        ))->toBe('elevenlabs');
});
