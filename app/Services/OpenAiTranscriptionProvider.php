<?php

namespace App\Services;

use App\Contracts\TranscriptionProvider;
use App\Data\TranscriptionResult;
use App\Exceptions\TranscriptionProcessingException;
use App\Models\Transcription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiTranscriptionProvider implements TranscriptionProvider
{
    public function __construct(
        private readonly ProviderErrorMessage $errorMessage,
    ) {}

    public function transcribe(
        Transcription $transcription,
        string $mediaPath,
        string $apiKey,
    ): TranscriptionResult {
        $stream = fopen($mediaPath, 'rb');

        if ($stream === false) {
            throw new TranscriptionProcessingException(
                'Não foi possível abrir a mídia preparada para transcrição.',
            );
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(
                    (int) config('transcription.processing.provider_connect_timeout_seconds'),
                )
                ->timeout((int) config('transcription.processing.provider_timeout_seconds'))
                ->attach('file', $stream, basename($mediaPath))
                ->post(
                    config('transcription.providers.openai.endpoint'),
                    $this->requestFields($transcription),
                );
        } catch (ConnectionException) {
            throw new TranscriptionProcessingException(
                $this->errorMessage->forConnection('openai'),
            );
        } finally {
            fclose($stream);
        }

        if ($response->failed()) {
            throw new TranscriptionProcessingException(
                $this->errorMessage->forStatus('openai', $response->status()),
            );
        }

        $payload = $response->json();
        $text = data_get($payload, 'text');

        if (! is_array($payload) || ! is_string($text)) {
            throw new TranscriptionProcessingException(
                'A OpenAI retornou uma resposta de transcrição inválida.',
            );
        }

        return new TranscriptionResult(
            text: $text,
            language: $this->language($payload),
            segments: $this->segments($payload),
        );
    }

    /**
     * @return array<string, string>
     */
    private function requestFields(Transcription $transcription): array
    {
        if ($transcription->model === 'gpt-4o-transcribe-diarize') {
            $fields = [
                'model' => $transcription->model,
                'response_format' => 'diarized_json',
            ];

            if ($transcription->duration_seconds > 30) {
                $fields['chunking_strategy'] = 'auto';
            }

            return $fields;
        }

        return [
            'model' => $transcription->model,
            'response_format' => $transcription->model === 'whisper-1'
                ? 'verbose_json'
                : 'json',
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function language(array $payload): ?string
    {
        $language = data_get($payload, 'language');

        if (is_string($language)) {
            return $language;
        }

        $detectedLanguage = data_get($payload, 'languages.0.code');

        return is_string($detectedLanguage) ? $detectedLanguage : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{text: string, speaker: ?string, start: ?float, end: ?float}>
     */
    private function segments(array $payload): array
    {
        $segments = data_get($payload, 'segments', []);

        if (! is_array($segments)) {
            return [];
        }

        return collect($segments)
            ->filter(fn (mixed $segment): bool => is_array($segment) && is_string(data_get($segment, 'text')))
            ->map(fn (array $segment): array => [
                'text' => trim((string) data_get($segment, 'text')),
                'speaker' => is_string(data_get($segment, 'speaker'))
                    ? data_get($segment, 'speaker')
                    : null,
                'start' => is_numeric(data_get($segment, 'start'))
                    ? (float) data_get($segment, 'start')
                    : null,
                'end' => is_numeric(data_get($segment, 'end'))
                    ? (float) data_get($segment, 'end')
                    : null,
            ])
            ->values()
            ->all();
    }
}
