<?php

namespace App\Services;

use App\Data\TranscriptionExportDocument;
use App\Models\Transcription;

class TranscriptionExportFormatter
{
    public function format(Transcription $transcription): TranscriptionExportDocument
    {
        return new TranscriptionExportDocument(
            title: 'Transcrição',
            metadata: [
                ['label' => 'Arquivo', 'value' => $transcription->original_filename],
                ['label' => 'Data', 'value' => $transcription->created_at?->format('d/m/Y H:i') ?? '—'],
                ['label' => 'Duração', 'value' => $this->timestamp($transcription->duration_seconds)],
                ['label' => 'Provider', 'value' => (string) config("transcription.providers.{$transcription->provider}.label", $transcription->provider)],
                ['label' => 'Modelo', 'value' => (string) config("transcription.providers.{$transcription->provider}.models.{$transcription->model}.label", $transcription->model)],
            ],
            paragraphs: $this->paragraphs($transcription),
        );
    }

    /**
     * @return list<array{speaker_label: ?string, text: string, text_lines: list<string>, time_label: ?string, plain_text: string}>
     */
    private function paragraphs(Transcription $transcription): array
    {
        $segments = $transcription->segments ?? [];

        if ($transcription->diarization && is_array($segments) && $segments !== []) {
            $paragraphs = [];

            foreach ($segments as $segment) {
                if (! is_array($segment) || ! is_string(data_get($segment, 'text'))) {
                    continue;
                }

                $text = trim((string) data_get($segment, 'text'));

                if ($text === '') {
                    continue;
                }

                $speaker = data_get($segment, 'speaker');
                $speakerLabel = is_string($speaker) && trim($speaker) !== ''
                    ? '['.trim($speaker).']'
                    : '[Falante]';
                $start = data_get($segment, 'start');
                $end = data_get($segment, 'end');
                $timeLabel = is_numeric($start) && is_numeric($end)
                    ? '['.$this->timestamp((float) $start).' - '.$this->timestamp((float) $end).']'
                    : null;
                $plainText = $speakerLabel.' '.$text;

                if ($timeLabel !== null) {
                    $plainText .= ' '.$timeLabel;
                }

                $paragraphs[] = $this->paragraph(
                    speakerLabel: $speakerLabel,
                    text: $text,
                    timeLabel: $timeLabel,
                    plainText: $plainText,
                );
            }

            if ($paragraphs !== []) {
                return $paragraphs;
            }
        }

        $text = trim((string) $transcription->text);

        return [
            $this->paragraph(
                speakerLabel: null,
                text: $text,
                timeLabel: null,
                plainText: $text,
            ),
        ];
    }

    /**
     * @return array{speaker_label: ?string, text: string, text_lines: list<string>, time_label: ?string, plain_text: string}
     */
    private function paragraph(
        ?string $speakerLabel,
        string $text,
        ?string $timeLabel,
        string $plainText,
    ): array {
        $lines = preg_split('/\R/u', $text);

        return [
            'speaker_label' => $speakerLabel,
            'text' => $text,
            'text_lines' => is_array($lines) ? array_values($lines) : [$text],
            'time_label' => $timeLabel,
            'plain_text' => $plainText,
        ];
    }

    private function timestamp(float $seconds): string
    {
        $totalSeconds = max(0, (int) floor($seconds));

        return sprintf(
            '%02d:%02d:%02d',
            intdiv($totalSeconds, 3600),
            intdiv($totalSeconds % 3600, 60),
            $totalSeconds % 60,
        );
    }
}
