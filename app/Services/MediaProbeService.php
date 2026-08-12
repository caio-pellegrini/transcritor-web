<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

class MediaProbeService
{
    /**
     * @return array{duration_seconds: float, format_name: string}
     */
    public function inspect(UploadedFile $file, string $extension): array
    {
        try {
            $result = Process::timeout(30)->run([
                'ffprobe',
                '-v',
                'error',
                '-show_entries',
                'format=format_name,duration:stream=codec_type',
                '-of',
                'json',
                $file->getRealPath(),
            ]);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'media' => 'Não foi possível inspecionar o arquivo enviado.',
            ]);
        }

        if (! $result->successful()) {
            throw ValidationException::withMessages([
                'media' => 'O conteúdo enviado não é um áudio ou vídeo válido.',
            ]);
        }

        try {
            $metadata = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages([
                'media' => 'O arquivo retornou metadados inválidos.',
            ]);
        }

        $formatName = data_get($metadata, 'format.format_name');
        $duration = data_get($metadata, 'format.duration');
        $streams = data_get($metadata, 'streams', []);

        if (! is_string($formatName) || ! is_numeric($duration)) {
            throw ValidationException::withMessages([
                'media' => 'Não foi possível determinar o formato e a duração do arquivo.',
            ]);
        }

        $durationSeconds = (float) $duration;

        if (! is_finite($durationSeconds) || $durationSeconds <= 0) {
            throw ValidationException::withMessages([
                'media' => 'O arquivo não possui uma duração válida.',
            ]);
        }

        if ($durationSeconds > config('transcription.limits.max_duration_seconds')) {
            throw ValidationException::withMessages([
                'media' => 'O arquivo excede a duração máxima de 4 horas.',
            ]);
        }

        $hasAudio = collect($streams)->contains(
            fn (mixed $stream): bool => data_get($stream, 'codec_type') === 'audio',
        );

        if (! $hasAudio) {
            throw ValidationException::withMessages([
                'media' => 'O arquivo não contém uma faixa de áudio.',
            ]);
        }

        $detectedFormats = array_map('strtolower', explode(',', $formatName));
        $expectedFormats = config("transcription.format_names_by_extension.{$extension}", []);

        if (array_intersect($detectedFormats, $expectedFormats) === []) {
            throw ValidationException::withMessages([
                'media' => "O conteúdo real do arquivo não corresponde à extensão .{$extension}.",
            ]);
        }

        return [
            'duration_seconds' => $durationSeconds,
            'format_name' => $formatName,
        ];
    }
}
