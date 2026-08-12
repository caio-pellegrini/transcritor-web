<?php

use App\Jobs\ProcessTranscriptionJob;
use App\Models\Transcription;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('api key is never stored or exposed in plaintext, including an invalid-key failure', function () {
    $sentinel = 'sk-sentinel-never-leak-6f1c88d3';
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.connection', 'sqlite');
    config()->set('queue.failed.driver', 'null');
    Storage::fake('local');

    $id = (string) str()->ulid();
    $mediaPath = "transcriptions/{$id}/source.mp3";
    Storage::disk('local')->put($mediaPath, 'small fixture');

    $transcription = Transcription::query()->create([
        'id' => $id,
        'status' => Transcription::STATUS_AWAITING_CONFIRMATION,
        'original_filename' => 'private.mp3',
        'media_path' => $mediaPath,
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 13,
        'duration_seconds' => 60,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-transcribe',
        'diarization' => false,
        'expires_at' => now()->addDay(),
    ]);

    Http::fake([
        config('transcription.providers.openai.endpoint') => Http::response([
            'error' => [
                'message' => "Invalid Authorization: Bearer {$sentinel}",
                'type' => 'invalid_request_error',
                'code' => 'invalid_api_key',
            ],
        ], 401),
    ]);

    $response = $this
        ->withSession(['transcription_unlocked' => true])
        ->withHeader('X-Transcription-Api-Key', $sentinel)
        ->post(route('transcriptions.start', $transcription));

    $response->assertRedirect(route('transcriptions.show', $transcription));

    $payload = (string) DB::table('jobs')->value('payload');

    expect($payload)->not->toContain($sentinel)
        ->and(json_encode(session()->all(), JSON_THROW_ON_ERROR))->not->toContain($sentinel)
        ->and($response->getContent())->not->toContain($sentinel)
        ->and(new ProcessTranscriptionJob('id', 'ciphertext'))->toBeInstanceOf(ShouldBeEncrypted::class)
        ->and(config('queue.failed.driver'))->toBe('null')
        ->and(Schema::hasTable('failed_jobs'))->toBeFalse();

    $this->artisan('queue:work', [
        'connection' => 'database',
        '--once' => true,
        '--tries' => 1,
    ])->assertSuccessful();

    $transcription->refresh();
    $statusResponse = $this
        ->withSession(['transcription_unlocked' => true])
        ->getJson(route('transcriptions.status', $transcription));

    expect($transcription->status)->toBe(Transcription::STATUS_FAILED)
        ->and($transcription->error_message)
        ->toBe('A API key da OpenAI é inválida ou não tem permissão.')
        ->not->toContain($sentinel)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($statusResponse->getContent())->not->toContain($sentinel);

    Storage::disk('local')->assertMissing("transcriptions/{$id}");

    foreach (glob(storage_path('logs/*')) ?: [] as $logPath) {
        if (is_file($logPath)) {
            expect((string) file_get_contents($logPath))->not->toContain($sentinel);
        }
    }
});
