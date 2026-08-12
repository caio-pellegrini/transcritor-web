<?php

use App\Jobs\ProcessTranscriptionJob;
use App\Models\Transcription;
use App\Services\PrepareTranscriptionMedia;
use App\Services\TranscriptionDiskSpaceGuard;
use App\Services\TranscriptionModelAvailability;
use App\Services\TranscriptionProviderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('long oversized audio is transcoded below 25 MiB and every temporary file is removed', function () {
    Storage::fake('local');
    $id = (string) str()->ulid();
    $directory = "transcriptions/{$id}";
    $mediaPath = "{$directory}/source.wav";
    Storage::disk('local')->makeDirectory($directory);
    $absoluteMediaPath = Storage::disk('local')->path($mediaPath);

    $generated = Process::timeout(30)->run([
        'ffmpeg',
        '-nostdin',
        '-hide_banner',
        '-loglevel',
        'error',
        '-f',
        'lavfi',
        '-i',
        'anullsrc=r=16000:cl=mono',
        '-t',
        '1200',
        '-c:a',
        'pcm_s16le',
        $absoluteMediaPath,
    ]);

    expect($generated->successful())->toBeTrue()
        ->and(filesize($absoluteMediaPath))->toBeGreaterThan(
            config('transcription.providers.openai.constraints.max_file_size_bytes'),
        );

    $transcription = Transcription::query()->create([
        'id' => $id,
        'status' => Transcription::STATUS_QUEUED,
        'progress_stage' => 'queued',
        'original_filename' => 'long.wav',
        'media_path' => $mediaPath,
        'extension' => 'wav',
        'mime_type' => 'audio/wav',
        'size_bytes' => filesize($absoluteMediaPath),
        'duration_seconds' => 1200,
        'provider' => 'openai',
        'model' => 'gpt-4o-mini-transcribe',
        'diarization' => false,
    ]);
    $multipartBytes = null;

    Http::fake(function (Request $request) use (&$multipartBytes) {
        $multipartBytes = strlen($request->body());

        return Http::response(['text' => 'Transcrição do arquivo longo.']);
    });

    (new ProcessTranscriptionJob(
        $transcription->id,
        Crypt::encryptString('transcode-test-key'),
    ))->handle(
        app(TranscriptionModelAvailability::class),
        app(TranscriptionDiskSpaceGuard::class),
        app(PrepareTranscriptionMedia::class),
        app(TranscriptionProviderFactory::class),
    );

    expect($multipartBytes)->toBeInt()
        ->toBeLessThan(config('transcription.providers.openai.constraints.max_file_size_bytes'))
        ->and($transcription->refresh()->status)->toBe(Transcription::STATUS_COMPLETED)
        ->and($transcription->text)->toBe('Transcrição do arquivo longo.');
    Storage::disk('local')->assertMissing($directory);
});
