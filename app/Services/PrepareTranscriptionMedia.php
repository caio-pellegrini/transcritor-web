<?php

namespace App\Services;

use App\Exceptions\TranscriptionProcessingException;
use App\Models\Transcription;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrepareTranscriptionMedia
{
    /**
     * @param  array{available: bool, reason: ?string, requires_transcode: bool, bitrate_kbps: ?int}  $availability
     */
    public function handle(Transcription $transcription, array $availability): string
    {
        $sourcePath = Storage::disk('local')->path($transcription->media_path);

        if (! is_file($sourcePath)) {
            throw new TranscriptionProcessingException(
                'A mídia temporária não foi encontrada. Envie o arquivo novamente.',
            );
        }

        if (! $availability['requires_transcode']) {
            return $sourcePath;
        }

        $bitrate = $availability['bitrate_kbps'];

        if (! is_int($bitrate)) {
            throw new TranscriptionProcessingException(
                'Não foi possível calcular a preparação da mídia.',
            );
        }

        $outputPath = Storage::disk('local')->path(
            "transcriptions/{$transcription->getKey()}/transcoded.webm",
        );

        try {
            $result = Process::timeout(
                (int) config('transcription.processing.ffmpeg_timeout_seconds'),
            )->run([
                'ffmpeg',
                '-nostdin',
                '-hide_banner',
                '-loglevel',
                'error',
                '-y',
                '-i',
                $sourcePath,
                '-map',
                '0:a:0',
                '-vn',
                '-ac',
                '1',
                '-c:a',
                'libopus',
                '-b:a',
                "{$bitrate}k",
                '-vbr',
                'on',
                '-application',
                'voip',
                $outputPath,
            ]);
        } catch (Throwable) {
            throw new TranscriptionProcessingException(
                'O ffmpeg excedeu o tempo limite ao preparar a mídia.',
            );
        }

        if (! $result->successful() || ! is_file($outputPath)) {
            throw new TranscriptionProcessingException(
                'Não foi possível converter o áudio para o formato aceito pela OpenAI.',
            );
        }

        $outputSize = filesize($outputPath);
        $maximumSize = (int) config(
            'transcription.providers.openai.constraints.max_file_size_bytes',
        );

        if (! is_int($outputSize) || $outputSize > $maximumSize) {
            throw new TranscriptionProcessingException(
                'Mesmo após a conversão, o arquivo excedeu o limite da OpenAI.',
            );
        }

        return $outputPath;
    }
}
