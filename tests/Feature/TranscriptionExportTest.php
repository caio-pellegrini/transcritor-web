<?php

use App\Models\Transcription;
use App\Services\TranscriptionExportFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;

uses(RefreshDatabase::class);

function completedExportTranscription(array $attributes = []): Transcription
{
    return Transcription::query()->create(array_merge([
        'id' => (string) str()->ulid(),
        'status' => Transcription::STATUS_COMPLETED,
        'progress_stage' => 'completed',
        'original_filename' => 'reunião especial.mp3',
        'media_path' => 'transcriptions/export/source.mp3',
        'extension' => 'mp3',
        'mime_type' => 'audio/mpeg',
        'size_bytes' => 100,
        'duration_seconds' => 65,
        'provider' => 'elevenlabs',
        'model' => 'scribe_v2',
        'diarization' => true,
        'text' => 'Olá mundo.',
        'language' => 'pt',
        'segments' => [
            [
                'speaker' => 'A&B',
                'text' => "Olá <script>alert(1)</script>\nsegunda linha",
                'start' => 1.9,
                'end' => 3.2,
            ],
        ],
        'finished_at' => now(),
    ], $attributes));
}

test('the shared formatter produces the required diarized plain text', function () {
    $transcription = completedExportTranscription();

    $text = app(TranscriptionExportFormatter::class)
        ->format($transcription)
        ->plainText();

    expect($text)->toBe(
        "[A&B] Olá <script>alert(1)</script>\nsegunda linha [00:00:01 - 00:00:03]",
    );
});

test('html export escapes provider content and preserves the shared format', function () {
    $transcription = completedExportTranscription();

    $response = $this
        ->withSession(['transcription_unlocked' => true])
        ->get(route('transcriptions.export.html', $transcription));

    $response
        ->assertOk()
        ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)
        ->assertSee('[A&amp;B]', false)
        ->assertSee('[00:00:01 - 00:00:03]');

    expect($response->getContent())
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('<br>');
});

test('docx export is valid OOXML, contains metadata, and writes no application file', function () {
    Storage::fake('local');
    $transcription = completedExportTranscription();

    $response = $this
        ->withSession(['transcription_unlocked' => true])
        ->get(route('transcriptions.export.docx', $transcription));

    $response
        ->assertOk()
        ->assertDownload('reuniao-especial.docx')
        ->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        );

    $content = $response->streamedContent();
    $temporary = tmpfile();

    expect($temporary)->not->toBeFalse();
    fwrite($temporary, $content);
    $path = stream_get_meta_data($temporary)['uri'];
    $word = IOFactory::load($path, 'Word2007');
    $zip = new ZipArchive;

    expect($word->getSections())->toHaveCount(1)
        ->and($zip->open($path))->toBeTrue();

    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    fclose($temporary);

    expect($xml)
        ->toBeString()
        ->toContain('reunião especial.mp3')
        ->toContain('A&amp;B')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->toContain('[00:00:01 - 00:00:03]');
    Storage::disk('local')->assertDirectoryEmpty('/');
});

test('completed status exposes the same backend-formatted text for clipboard use', function () {
    $transcription = completedExportTranscription();

    $this
        ->withSession(['transcription_unlocked' => true])
        ->getJson(route('transcriptions.status', $transcription))
        ->assertOk()
        ->assertJsonPath(
            'result.export_text',
            "[A&B] Olá <script>alert(1)</script>\nsegunda linha [00:00:01 - 00:00:03]",
        );
});

test('non-completed transcriptions cannot be exported', function (string $routeName) {
    $transcription = completedExportTranscription([
        'status' => Transcription::STATUS_FAILED,
        'progress_stage' => 'failed',
    ]);

    $this
        ->withSession(['transcription_unlocked' => true])
        ->get(route($routeName, $transcription))
        ->assertConflict();
})->with([
    'HTML' => 'transcriptions.export.html',
    'DOCX' => 'transcriptions.export.docx',
]);

test('export routes remain protected by the global unlock session', function (string $routeName) {
    $transcription = completedExportTranscription();

    $this->get(route($routeName, $transcription))->assertRedirect(route('unlock.show'));
})->with([
    'HTML' => 'transcriptions.export.html',
    'DOCX' => 'transcriptions.export.docx',
]);
