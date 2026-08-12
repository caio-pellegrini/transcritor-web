<?php

use App\Models\Transcription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/transcription-fixture-*') ?: [] as $temporaryPath) {
        if (is_file($temporaryPath)) {
            unlink($temporaryPath);
        }
    }
});

function mediaFixture(string $extension, ?string $originalName = null): UploadedFile
{
    $temporaryPath = tempnam(sys_get_temp_dir(), 'transcription-fixture-');

    if ($temporaryPath === false || ! copy(__DIR__."/../Fixtures/media/sample.{$extension}", $temporaryPath)) {
        throw new RuntimeException('Could not prepare the media fixture.');
    }

    return new UploadedFile(
        path: $temporaryPath,
        originalName: $originalName ?? "sample.{$extension}",
        test: true,
    );
}

test('accepts every supported media format after real ffprobe inspection', function (string $extension) {
    Storage::fake('local');

    $response = $this
        ->withSession(['transcription_unlocked' => true])
        ->post('/transcriptions', [
            'media' => mediaFixture($extension),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ]);

    $response->assertSessionHasNoErrors();
    $transcription = Transcription::query()->sole();

    $response->assertRedirect(route('transcriptions.show', $transcription));
    expect($transcription->duration_seconds)->toBeGreaterThan(0.0)
        ->and($transcription->extension)->toBe($extension)
        ->and($transcription->status)->toBe('awaiting_confirmation');
    Storage::disk('local')->assertExists($transcription->media_path);
})->with([
    'MP3' => 'mp3',
    'MP4' => 'mp4',
    'MPEG' => 'mpeg',
    'MPGA' => 'mpga',
    'M4A' => 'm4a',
    'WAV' => 'wav',
    'WEBM' => 'webm',
]);

test('rejects a valid media file renamed to an incompatible extension', function () {
    Storage::fake('local');

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => mediaFixture('mp3', 'arquivo-falso.mp4'),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors('media');

    expect(Transcription::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('rejects non-media content and leaves no stored file', function () {
    Storage::fake('local');

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => UploadedFile::fake()->createWithContent('falso.mp3', 'isto não é mídia'),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors('media');

    expect(Transcription::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('rejects files above the centralized 500 MB limit', function () {
    Storage::fake('local');

    $oversizedFile = UploadedFile::fake()->create(
        'grande.mp3',
        (int) (config('transcription.limits.max_upload_bytes') / 1024) + 1,
        'audio/mpeg',
    );

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => $oversizedFile,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors('media');

    expect(Transcription::query()->count())->toBe(0);
});

test('rejects an upload clearly when the storage reserve is exhausted', function () {
    Storage::fake('local');
    config()->set('transcription.storage.minimum_free_bytes', PHP_INT_MAX);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => mediaFixture('mp3'),
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors([
            'media' => 'O servidor está sem espaço livre suficiente para receber o arquivo. Libere espaço e tente novamente.',
        ]);

    expect(Transcription::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('reports a clear message when php cannot write the temporary upload', function () {
    Storage::fake('local');
    $failedUpload = new UploadedFile(
        path: __DIR__.'/../Fixtures/media/sample.mp3',
        originalName: 'sample.mp3',
        mimeType: 'audio/mpeg',
        error: UPLOAD_ERR_CANT_WRITE,
        test: true,
    );

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => $failedUpload,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors([
            'media' => 'Não foi possível receber o arquivo. Verifique o espaço livre no servidor e tente novamente.',
        ]);

    expect(Transcription::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('reports the 500 MB limit when php rejects an oversized upload', function () {
    Storage::fake('local');
    $failedUpload = new UploadedFile(
        path: __DIR__.'/../Fixtures/media/sample.mp3',
        originalName: 'oversized.mp3',
        mimeType: 'audio/mpeg',
        error: UPLOAD_ERR_INI_SIZE,
        test: true,
    );

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => $failedUpload,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors([
            'media' => 'O arquivo excede o limite de 500 MB.',
        ]);

    expect(Transcription::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('rejects media above four hours based on ffprobe duration', function () {
    Storage::fake('local');
    Process::fake([
        '*' => Process::result(output: json_encode([
            'streams' => [['codec_type' => 'audio']],
            'format' => [
                'format_name' => 'mp3',
                'duration' => '14400.001',
            ],
        ], JSON_THROW_ON_ERROR)),
    ]);

    $media = mediaFixture('mp3');
    $temporaryPath = $media->getRealPath();

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => $media,
            'provider' => 'openai',
            'model' => 'gpt-4o-mini-transcribe',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors([
            'media' => 'O arquivo excede a duração máxima de 4 horas.',
        ]);

    Process::assertRan(fn ($process): bool => is_array($process->command)
        && $process->command[0] === 'ffprobe'
        && $process->command[array_key_last($process->command)] === $temporaryPath);
    expect(Transcription::query()->count())->toBe(0);
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('rejects unknown models instead of falling back to other metadata', function () {
    Storage::fake('local');

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from('/')
        ->post('/transcriptions', [
            'media' => mediaFixture('mp3'),
            'provider' => 'openai',
            'model' => 'modelo-inexistente',
            'diarization' => false,
        ])
        ->assertRedirect('/')
        ->assertSessionHasErrors('model');

    expect(Transcription::query()->count())->toBe(0);
});

test('recalculates provider and model without changing or uploading media', function () {
    Storage::fake('local');
    Storage::disk('local')->put('transcriptions/example/source.mp3', 'fixture');

    $transcription = Transcription::query()->create([
        'id' => (string) str()->ulid(),
        'status' => 'awaiting_confirmation',
        'original_filename' => 'example.mp3',
        'media_path' => 'transcriptions/example/source.mp3',
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 100,
        'duration_seconds' => 3600,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-transcribe',
        'diarization' => false,
    ]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->patch(route('transcriptions.estimate', $transcription), [
            'provider' => 'elevenlabs',
            'model' => 'scribe_v2',
            'diarization' => true,
        ])
        ->assertRedirect(route('transcriptions.show', $transcription));

    $transcription->refresh();

    expect($transcription->provider)->toBe('elevenlabs')
        ->and($transcription->model)->toBe('scribe_v2')
        ->and($transcription->diarization)->toBeTrue()
        ->and($transcription->media_path)->toBe('transcriptions/example/source.mp3');
    Storage::disk('local')->assertExists('transcriptions/example/source.mp3');
});

test('rejects recalculation to a model that cannot handle the known duration', function () {
    Storage::fake('local');
    Storage::disk('local')->put('transcriptions/long/source.mp3', 'fixture');

    $transcription = Transcription::query()->create([
        'id' => (string) str()->ulid(),
        'status' => 'awaiting_confirmation',
        'original_filename' => 'long.mp3',
        'media_path' => 'transcriptions/long/source.mp3',
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 10 * 1024 * 1024,
        'duration_seconds' => 1800,
        'provider' => 'elevenlabs',
        'model' => 'scribe_v2',
        'diarization' => true,
    ]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->from(route('transcriptions.show', $transcription))
        ->patch(route('transcriptions.estimate', $transcription), [
            'provider' => 'openai',
            'model' => 'gpt-4o-transcribe-diarize',
            'diarization' => true,
        ])
        ->assertRedirect(route('transcriptions.show', $transcription))
        ->assertSessionHasErrors([
            'model' => 'Acima de 25 minutos, use a diarização da ElevenLabs.',
        ]);

    expect($transcription->refresh()->provider)->toBe('elevenlabs')
        ->and($transcription->model)->toBe('scribe_v2');
});

test('long file estimate exposes disabled model reasons and recommends elevenlabs', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([
            'USDBRL' => ['bid' => '5.0000', 'timestamp' => '1786377600'],
        ]),
    ]);

    $transcription = Transcription::query()->create([
        'id' => (string) str()->ulid(),
        'status' => 'awaiting_confirmation',
        'original_filename' => 'long.mp3',
        'media_path' => 'transcriptions/long/source.mp3',
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 30 * 1024 * 1024,
        'duration_seconds' => 1800,
        'provider' => 'elevenlabs',
        'model' => 'scribe_v2',
        'diarization' => true,
    ]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->get(route('transcriptions.show', $transcription))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transcription.recommended_provider', 'elevenlabs')
            ->where('transcription.model_availability.elevenlabs.scribe_v2.available', true)
            ->where('transcription.model_availability.elevenlabs.scribe_v2.requires_transcode', false)
            ->where('transcription.model_availability.openai.gpt-4o-mini-transcribe.available', false)
            ->where('transcription.model_availability.openai.gpt-transcribe.available', false)
            ->where('transcription.model_availability.openai.gpt-4o-transcribe-diarize.reason', 'Acima de 25 minutos, use a diarização da ElevenLabs.'));
});

test('shows the estimate without exposing the private media path', function () {
    Http::fake([
        config('transcription.exchange_rate.endpoint') => Http::response([
            'USDBRL' => ['bid' => '5.0000', 'timestamp' => '1786377600'],
        ]),
    ]);

    $unsafeFilename = '</script><script>alert(1)</script>.mp3';

    $transcription = Transcription::query()->create([
        'id' => (string) str()->ulid(),
        'status' => 'awaiting_confirmation',
        'original_filename' => $unsafeFilename,
        'media_path' => 'transcriptions/private/source.mp3',
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 100,
        'duration_seconds' => 60,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-transcribe',
        'diarization' => false,
    ]);

    $response = $this
        ->withSession(['transcription_unlocked' => true])
        ->get(route('transcriptions.show', $transcription));

    $response
        ->assertOk()
        ->assertDontSee($unsafeFilename, false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('transcriptions/index')
            ->where('transcription.id', $transcription->id)
            ->where('transcription.original_filename', $unsafeFilename)
            ->where('transcription.estimate.cost_usd', 0.003)
            ->missing('transcription.media_path'));
});
