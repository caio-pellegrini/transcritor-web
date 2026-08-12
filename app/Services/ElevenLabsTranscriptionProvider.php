<?php

namespace App\Services;

use App\Contracts\TranscriptionProvider;
use App\Data\TranscriptionResult;
use App\Exceptions\TranscriptionProcessingException;
use App\Models\Transcription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ElevenLabsTranscriptionProvider implements TranscriptionProvider
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
                'Não foi possível abrir a mídia para transcrição.',
            );
        }

        try {
            $response = Http::withHeaders(['xi-api-key' => $apiKey])
                ->acceptJson()
                ->connectTimeout(
                    (int) config('transcription.processing.provider_connect_timeout_seconds'),
                )
                ->timeout((int) config('transcription.processing.provider_timeout_seconds'))
                ->attach('file', $stream, basename($mediaPath))
                ->post(config('transcription.providers.elevenlabs.endpoint'), [
                    'model_id' => $transcription->model,
                    'diarize' => $transcription->diarization ? 'true' : 'false',
                    'tag_audio_events' => 'false',
                    'timestamps_granularity' => 'word',
                ]);
        } catch (ConnectionException) {
            throw new TranscriptionProcessingException(
                $this->errorMessage->forConnection('elevenlabs'),
            );
        } finally {
            fclose($stream);
        }

        if ($response->failed()) {
            throw new TranscriptionProcessingException(
                $this->errorMessage->forStatus('elevenlabs', $response->status()),
            );
        }

        $payload = $response->json();
        $text = data_get($payload, 'text');

        if (! is_array($payload) || ! is_string($text)) {
            throw new TranscriptionProcessingException(
                'A ElevenLabs retornou uma resposta de transcrição inválida.',
            );
        }

        return new TranscriptionResult(
            text: $text,
            language: is_string(data_get($payload, 'language_code'))
                ? data_get($payload, 'language_code')
                : null,
            segments: $this->groupWords(data_get($payload, 'words', []), $transcription->diarization),
        );
    }

    /**
     * @return list<array{text: string, speaker: ?string, start: ?float, end: ?float}>
     */
    private function groupWords(mixed $words, bool $diarization): array
    {
        if (! is_array($words)) {
            return [];
        }

        $segments = [];
        $current = null;

        foreach ($words as $word) {
            if (! is_array($word) || data_get($word, 'type') === 'audio_event') {
                continue;
            }

            $text = data_get($word, 'text');

            if (! is_string($text)) {
                continue;
            }

            if (data_get($word, 'type') === 'spacing') {
                if (is_array($current)) {
                    $current['text'] .= $text;
                }

                continue;
            }

            $speaker = $diarization && is_string(data_get($word, 'speaker_id'))
                ? data_get($word, 'speaker_id')
                : null;

            if (is_array($current) && $current['speaker'] !== $speaker) {
                $segments[] = $this->finishSegment($current);
                $current = null;
            }

            if ($current === null) {
                $current = [
                    'text' => '',
                    'speaker' => $speaker,
                    'start' => is_numeric(data_get($word, 'start'))
                        ? (float) data_get($word, 'start')
                        : null,
                    'end' => null,
                ];
            }

            $current['text'] .= $text;

            if (is_numeric(data_get($word, 'end'))) {
                $current['end'] = (float) data_get($word, 'end');
            }
        }

        if (is_array($current)) {
            $segments[] = $this->finishSegment($current);
        }

        return $segments;
    }

    /**
     * @param  array{text: string, speaker: ?string, start: ?float, end: ?float}  $segment
     * @return array{text: string, speaker: ?string, start: ?float, end: ?float}
     */
    private function finishSegment(array $segment): array
    {
        $segment['text'] = trim($segment['text']);

        return $segment;
    }
}
