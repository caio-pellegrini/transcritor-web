<?php

use App\Exceptions\TranscriptionProcessingException;
use App\Models\Transcription;
use App\Services\ElevenLabsTranscriptionProvider;
use App\Services\OpenAiTranscriptionProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Storage::disk('local')->put('provider/source.mp3', 'small audio fixture');
});

function providerModel(string $provider, string $model, bool $diarization = false): Transcription
{
    return new Transcription([
        'provider' => $provider,
        'model' => $model,
        'diarization' => $diarization,
        'duration_seconds' => 60,
    ]);
}

function multipartValue(Request $request, string $name): mixed
{
    foreach ($request->data() as $part) {
        if (is_array($part) && ($part['name'] ?? null) === $name) {
            return $part['contents'] ?? null;
        }
    }

    return null;
}

test('openai sends a streamed multipart request and normalizes simple text', function () {
    Http::fake([
        config('transcription.providers.openai.endpoint') => Http::response([
            'text' => 'Uma transcrição simples.',
        ]),
    ]);

    $result = app(OpenAiTranscriptionProvider::class)->transcribe(
        providerModel('openai', 'gpt-transcribe'),
        Storage::disk('local')->path('provider/source.mp3'),
        'openai-test-key',
    );

    expect($result->text)->toBe('Uma transcrição simples.')
        ->and($result->language)->toBeNull()
        ->and($result->segments)->toBe([]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === config('transcription.providers.openai.endpoint')
            && $request->hasHeader('Authorization', 'Bearer openai-test-key')
            && $request->hasFile('file', filename: 'source.mp3')
            && multipartValue($request, 'model') === 'gpt-transcribe'
            && multipartValue($request, 'response_format') === 'json';
    });
});

test('openai normalizes whisper timestamps and detected language', function () {
    Http::fake([
        config('transcription.providers.openai.endpoint') => Http::response([
            'text' => 'Olá mundo.',
            'language' => 'portuguese',
            'segments' => [
                ['text' => ' Olá mundo. ', 'start' => 0.25, 'end' => 1.5],
            ],
        ]),
    ]);

    $result = app(OpenAiTranscriptionProvider::class)->transcribe(
        providerModel('openai', 'whisper-1'),
        Storage::disk('local')->path('provider/source.mp3'),
        'openai-test-key',
    );

    expect($result->language)->toBe('portuguese')
        ->and($result->segments)->toBe([
            ['text' => 'Olá mundo.', 'speaker' => null, 'start' => 0.25, 'end' => 1.5],
        ]);

    Http::assertSent(fn (Request $request): bool => multipartValue($request, 'response_format') === 'verbose_json');
});

test('elevenlabs sends original media, disables audio events, and groups words by speaker', function () {
    Http::fake([
        config('transcription.providers.elevenlabs.endpoint') => Http::response([
            'text' => 'Olá mundo.',
            'language_code' => 'pt',
            'words' => [
                ['type' => 'word', 'text' => 'Olá', 'start' => 0, 'end' => 0.4, 'speaker_id' => 'speaker_0'],
                ['type' => 'spacing', 'text' => ' '],
                ['type' => 'audio_event', 'text' => '(risos)', 'start' => 0.5, 'end' => 0.8],
                ['type' => 'word', 'text' => 'mundo', 'start' => 0.9, 'end' => 1.3, 'speaker_id' => 'speaker_1'],
                ['type' => 'spacing', 'text' => '.'],
            ],
        ]),
    ]);

    $result = app(ElevenLabsTranscriptionProvider::class)->transcribe(
        providerModel('elevenlabs', 'scribe_v2', true),
        Storage::disk('local')->path('provider/source.mp3'),
        'elevenlabs-test-key',
    );

    expect($result->language)->toBe('pt')
        ->and($result->segments)->toBe([
            ['text' => 'Olá', 'speaker' => 'speaker_0', 'start' => 0.0, 'end' => 0.4],
            ['text' => 'mundo.', 'speaker' => 'speaker_1', 'start' => 0.9, 'end' => 1.3],
        ]);

    Http::assertSent(function (Request $request): bool {
        return $request->url() === config('transcription.providers.elevenlabs.endpoint')
            && $request->hasHeader('xi-api-key', 'elevenlabs-test-key')
            && $request->hasFile('file', filename: 'source.mp3')
            && multipartValue($request, 'model_id') === 'scribe_v2'
            && multipartValue($request, 'diarize') === 'true'
            && multipartValue($request, 'tag_audio_events') === 'false'
            && multipartValue($request, 'timestamps_granularity') === 'word';
    });
});

test('provider errors are useful and never include the api key', function (
    string $provider,
    string $model,
    int $status,
    string $expectedMessage,
) {
    $apiKey = "sentinel-{$provider}-{$status}";
    $endpoint = config("transcription.providers.{$provider}.endpoint");
    Http::fake([
        $endpoint => Http::response([
            'error' => ['message' => "Rejected {$apiKey}"],
        ], $status),
    ]);

    $service = $provider === 'openai'
        ? app(OpenAiTranscriptionProvider::class)
        : app(ElevenLabsTranscriptionProvider::class);

    try {
        $service->transcribe(
            providerModel($provider, $model),
            Storage::disk('local')->path('provider/source.mp3'),
            $apiKey,
        );
        $this->fail('The provider exception was not thrown.');
    } catch (TranscriptionProcessingException $exception) {
        expect($exception->userMessage)->toBe($expectedMessage)
            ->not->toContain($apiKey)
            ->and($exception->getMessage())->not->toContain($apiKey);
    }
})->with([
    'OpenAI invalid key' => ['openai', 'gpt-transcribe', 401, 'A API key da OpenAI é inválida ou não tem permissão.'],
    'ElevenLabs invalid key' => ['elevenlabs', 'scribe_v2', 401, 'A API key da ElevenLabs é inválida ou não tem permissão.'],
    'OpenAI rate limit' => ['openai', 'gpt-transcribe', 429, 'A OpenAI limitou as requisições. Tente novamente mais tarde.'],
    'ElevenLabs server failure' => ['elevenlabs', 'scribe_v2', 503, 'A ElevenLabs está indisponível no momento.'],
]);

test('provider connection timeout is sanitized without an automatic retry', function () {
    Http::fake([
        config('transcription.providers.openai.endpoint') => Http::failedConnection(
            'Connection failed with Authorization: Bearer timeout-sentinel-key',
        ),
    ]);

    try {
        app(OpenAiTranscriptionProvider::class)->transcribe(
            providerModel('openai', 'gpt-transcribe'),
            Storage::disk('local')->path('provider/source.mp3'),
            'timeout-sentinel-key',
        );
        $this->fail('The connection exception was not translated.');
    } catch (TranscriptionProcessingException $exception) {
        expect($exception->userMessage)
            ->toBe('Não foi possível conectar à OpenAI dentro do tempo limite.')
            ->not->toContain('timeout-sentinel-key');
    }

    Http::assertSentCount(1);
});
