<?php

use App\Jobs\ProcessTranscriptionJob;
use App\Models\Transcription;
use App\Services\PrepareTranscriptionMedia;
use App\Services\TranscriptionDiskSpaceGuard;
use App\Services\TranscriptionModelAvailability;
use App\Services\TranscriptionProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function workflowTranscription(array $attributes = []): Transcription
{
    $id = (string) str()->ulid();
    $mediaPath = "transcriptions/{$id}/source.mp3";

    Storage::disk('local')->put($mediaPath, 'small fixture');

    return Transcription::query()->create(array_merge([
        'id' => $id,
        'status' => Transcription::STATUS_AWAITING_CONFIRMATION,
        'original_filename' => 'example.mp3',
        'media_path' => $mediaPath,
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 13,
        'duration_seconds' => 60,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-transcribe',
        'diarization' => false,
        'expires_at' => now()->addDay(),
    ], $attributes));
}

beforeEach(function () {
    Storage::fake('local');
});

test('start requires the dedicated api key header', function () {
    Queue::fake();
    $transcription = workflowTranscription();

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from(route('transcriptions.show', $transcription))
        ->post(route('transcriptions.start', $transcription))
        ->assertRedirect(route('transcriptions.show', $transcription))
        ->assertSessionHasErrors('api_key');

    expect($transcription->refresh()->status)
        ->toBe(Transcription::STATUS_AWAITING_CONFIRMATION);
    Queue::assertNothingPushed();
});

test('start rejects a model that is unavailable for the known media duration', function () {
    Queue::fake();
    $transcription = workflowTranscription([
        'duration_seconds' => 1800,
        'provider' => 'openai',
        'model' => 'gpt-4o-transcribe-diarize',
        'diarization' => true,
    ]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->withHeader('X-Transcription-Api-Key', 'unused-key')
        ->from(route('transcriptions.show', $transcription))
        ->post(route('transcriptions.start', $transcription))
        ->assertRedirect(route('transcriptions.show', $transcription))
        ->assertSessionHasErrors([
            'transcription' => 'Acima de 25 minutos, use a diarização da ElevenLabs.',
        ]);

    expect($transcription->refresh()->status)
        ->toBe(Transcription::STATUS_AWAITING_CONFIRMATION);
    Queue::assertNothingPushed();
});

test('duplicate start requests dispatch exactly one job', function () {
    Queue::fake();
    $transcription = workflowTranscription();
    $headers = ['X-Transcription-Api-Key' => 'stage-four-sentinel-key'];

    $this
        ->withSession(['transcription_unlocked' => true])
        ->withHeaders($headers)
        ->post(route('transcriptions.start', $transcription))
        ->assertRedirect(route('transcriptions.show', $transcription));

    $this
        ->withSession(['transcription_unlocked' => true])
        ->withHeaders($headers)
        ->post(route('transcriptions.start', $transcription))
        ->assertRedirect(route('transcriptions.show', $transcription));

    expect($transcription->refresh()->status)
        ->toBe(Transcription::STATUS_QUEUED)
        ->and($transcription->progress_stage)->toBe('queued')
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))
        ->not->toContain('stage-four-sentinel-key');

    Queue::assertPushed(ProcessTranscriptionJob::class, 1);
    Queue::assertPushed(
        ProcessTranscriptionJob::class,
        fn (ProcessTranscriptionJob $job): bool => $job->transcriptionId === $transcription->id
            && Crypt::decryptString($job->encryptedApiKey) === 'stage-four-sentinel-key'
            && $job->tries === 1
            && $job->failOnTimeout,
    );
});

test('status endpoint returns only polling data', function () {
    $transcription = workflowTranscription([
        'status' => Transcription::STATUS_PROCESSING,
        'progress_stage' => 'transcribing',
        'progress_current' => 2,
        'progress_total' => 3,
    ]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->getJson(route('transcriptions.status', $transcription))
        ->assertOk()
        ->assertExactJson([
            'id' => $transcription->id,
            'status' => Transcription::STATUS_PROCESSING,
            'progress' => [
                'stage' => 'transcribing',
                'current' => 2,
                'total' => 3,
            ],
            'error_message' => null,
            'result' => null,
        ])
        ->assertJsonMissing(['media_path' => $transcription->media_path]);
});

test('worker records real progress, persists the result, and removes media after success', function () {
    Http::fake([
        config('transcription.providers.openai.endpoint') => Http::response([
            'text' => 'Texto transcrito.',
        ]),
    ]);
    $transcription = workflowTranscription([
        'status' => Transcription::STATUS_QUEUED,
        'progress_stage' => 'queued',
        'expires_at' => null,
    ]);
    $seenStages = [];

    Transcription::updated(function (Transcription $updated) use (&$seenStages, $transcription): void {
        if ($updated->is($transcription)) {
            $seenStages[] = $updated->progress_stage;
        }
    });

    (new ProcessTranscriptionJob(
        $transcription->id,
        Crypt::encryptString('test-api-key'),
    ))->handle(
        app(TranscriptionModelAvailability::class),
        app(TranscriptionDiskSpaceGuard::class),
        app(PrepareTranscriptionMedia::class),
        app(TranscriptionProviderFactory::class),
    );

    $transcription->refresh();

    expect($seenStages)
        ->toContain('preparing', 'transcribing', 'finalizing', 'completed')
        ->and($transcription->status)->toBe(Transcription::STATUS_COMPLETED)
        ->and($transcription->progress_current)->toBeNull()
        ->and($transcription->progress_total)->toBeNull()
        ->and($transcription->text)->toBe('Texto transcrito.')
        ->and($transcription->finished_at)->not->toBeNull();
    Storage::disk('local')->assertMissing("transcriptions/{$transcription->id}");
});

test('worker failure is sanitized, terminal, and removes media', function () {
    Http::fake([
        config('transcription.providers.openai.endpoint') => Http::response([
            'error' => ['message' => 'raw-provider-error-with-sensitive-detail'],
        ], 500),
    ]);
    $transcription = workflowTranscription([
        'status' => Transcription::STATUS_QUEUED,
        'progress_stage' => 'queued',
        'expires_at' => null,
    ]);
    (new ProcessTranscriptionJob(
        $transcription->id,
        Crypt::encryptString('test-api-key'),
    ))->handle(
        app(TranscriptionModelAvailability::class),
        app(TranscriptionDiskSpaceGuard::class),
        app(PrepareTranscriptionMedia::class),
        app(TranscriptionProviderFactory::class),
    );

    $transcription->refresh();

    expect($transcription->status)
        ->toBe(Transcription::STATUS_FAILED)
        ->and($transcription->error_message)
        ->toBe('A OpenAI está indisponível no momento.')
        ->not->toContain('raw-provider-error-with-sensitive-detail');
    Storage::disk('local')->assertMissing("transcriptions/{$transcription->id}");
});

test('worker fails clearly without calling a provider or orphaning media when disk is full', function () {
    Http::fake();
    config()->set('transcription.storage.minimum_free_bytes', PHP_INT_MAX);
    $transcription = workflowTranscription([
        'status' => Transcription::STATUS_QUEUED,
        'progress_stage' => 'queued',
        'expires_at' => null,
    ]);

    (new ProcessTranscriptionJob(
        $transcription->id,
        Crypt::encryptString('unused-test-key'),
    ))->handle(
        app(TranscriptionModelAvailability::class),
        app(TranscriptionDiskSpaceGuard::class),
        app(PrepareTranscriptionMedia::class),
        app(TranscriptionProviderFactory::class),
    );

    expect($transcription->refresh()->status)
        ->toBe(Transcription::STATUS_FAILED)
        ->and($transcription->error_message)
        ->toBe('O servidor ficou sem espaço livre suficiente para processar o arquivo. Libere espaço e envie novamente.');
    Http::assertNothingSent();
    Storage::disk('local')->assertMissing("transcriptions/{$transcription->id}");
});

test('a terminal worker timeout leaves no media or retryable state', function () {
    $transcription = workflowTranscription([
        'status' => Transcription::STATUS_PROCESSING,
        'progress_stage' => 'transcribing',
        'expires_at' => null,
    ]);
    $job = new ProcessTranscriptionJob(
        $transcription->id,
        Crypt::encryptString('unused-timeout-key'),
    );

    $job->failed(null);

    expect($transcription->refresh()->status)
        ->toBe(Transcription::STATUS_FAILED)
        ->and($transcription->error_message)
        ->toBe('O processamento excedeu o limite operacional. Envie o arquivo novamente.');
    Storage::disk('local')->assertMissing("transcriptions/{$transcription->id}");
});

test('expiration command removes only abandoned awaiting confirmation media', function () {
    $expired = workflowTranscription(['expires_at' => now()->subMinute()]);
    $future = workflowTranscription(['expires_at' => now()->addHour()]);
    $processing = workflowTranscription([
        'status' => Transcription::STATUS_PROCESSING,
        'expires_at' => now()->subMinute(),
    ]);

    $this->artisan('transcriptions:expire')
        ->expectsOutput('Transcrições expiradas: 1')
        ->assertSuccessful();

    expect($expired->refresh()->status)->toBe(Transcription::STATUS_EXPIRED)
        ->and($future->refresh()->status)->toBe(Transcription::STATUS_AWAITING_CONFIRMATION)
        ->and($processing->refresh()->status)->toBe(Transcription::STATUS_PROCESSING);
    Storage::disk('local')->assertMissing("transcriptions/{$expired->id}");
    Storage::disk('local')->assertExists($future->media_path);
    Storage::disk('local')->assertExists($processing->media_path);
});

test('expired estimate cannot be started before the cleanup command runs', function () {
    Queue::fake();
    $transcription = workflowTranscription(['expires_at' => now()->subSecond()]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->withHeader('X-Transcription-Api-Key', 'unused-key')
        ->from(route('transcriptions.show', $transcription))
        ->post(route('transcriptions.start', $transcription))
        ->assertRedirect(route('transcriptions.show', $transcription))
        ->assertSessionHasErrors('transcription');

    expect($transcription->refresh()->status)
        ->toBe(Transcription::STATUS_AWAITING_CONFIRMATION);
    Queue::assertNothingPushed();
});
