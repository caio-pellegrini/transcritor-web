<?php

test('transcription catalog contains the approved providers models capabilities and prices', function () {
    expect(getenv('APP_ENV'))->toBe('testing')
        ->and(getenv('SESSION_DRIVER'))->toBe('array');

    $catalog = require __DIR__.'/../../config/transcription.php';

    expect(array_keys($catalog['providers']))->toBe(['openai', 'elevenlabs'])
        ->and(array_keys($catalog['providers']['openai']['models']))->toBe([
            'gpt-transcribe',
            'whisper-1',
        ])
        ->and($catalog['providers']['openai']['models']['gpt-transcribe']['pricing']['usd'])->toBe(0.0045)
        ->and($catalog['providers']['openai']['models']['gpt-transcribe']['capabilities']['diarization'])->toBeFalse()
        ->and($catalog['providers']['openai']['models']['gpt-transcribe']['constraints']['max_duration_seconds'])->toBeNull()
        ->and(array_keys($catalog['providers']['elevenlabs']['models']))->toBe(['scribe_v2'])
        ->and($catalog['providers']['elevenlabs']['models']['scribe_v2']['pricing'])->toMatchArray([
            'type' => 'per_hour',
            'usd' => 0.22,
            'minimum_hours' => null,
        ])
        ->and($catalog['limits'])->toBe([
            'max_upload_bytes' => 500 * 1024 * 1024,
            'max_duration_seconds' => 4 * 60 * 60,
        ]);
});
