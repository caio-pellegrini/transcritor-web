<?php

namespace App\Services;

use App\Models\Transcription;

class TranscriptionModelAvailability
{
    /**
     * @return array<string, array<string, array{available: bool, reason: ?string, requires_transcode: bool, bitrate_kbps: ?int}>>
     */
    public function allFor(Transcription $transcription): array
    {
        $availability = [];

        foreach (config('transcription.providers') as $provider => $providerConfig) {
            foreach ($providerConfig['models'] as $model => $modelConfig) {
                $availability[$provider][$model] = $this->forValues(
                    provider: $provider,
                    model: $model,
                    durationSeconds: $transcription->duration_seconds,
                    sizeBytes: $transcription->size_bytes,
                );
            }
        }

        return $availability;
    }

    /**
     * @return array{available: bool, reason: ?string, requires_transcode: bool, bitrate_kbps: ?int}
     */
    public function forTranscription(Transcription $transcription): array
    {
        return $this->forValues(
            provider: $transcription->provider,
            model: $transcription->model,
            durationSeconds: $transcription->duration_seconds,
            sizeBytes: $transcription->size_bytes,
        );
    }

    /**
     * @return array{available: bool, reason: ?string, requires_transcode: bool, bitrate_kbps: ?int}
     */
    public function forValues(
        string $provider,
        string $model,
        float $durationSeconds,
        int $sizeBytes,
    ): array {
        $providerConfig = config("transcription.providers.{$provider}");
        $modelConfig = config("transcription.providers.{$provider}.models.{$model}");

        if (! is_array($providerConfig) || ! is_array($modelConfig)) {
            return $this->unavailable('Provider ou modelo inválido.');
        }

        $modelDurationLimit = data_get($modelConfig, 'constraints.max_duration_seconds');

        if (is_numeric($modelDurationLimit) && $durationSeconds > (float) $modelDurationLimit) {
            return $this->unavailable('Este modelo não aceita a duração deste áudio.');
        }

        $providerDurationLimit = data_get($providerConfig, 'constraints.max_duration_seconds');

        if (is_numeric($providerDurationLimit) && $durationSeconds > (float) $providerDurationLimit) {
            return $this->unavailable('A duração excede o limite deste provider.');
        }

        if ($provider !== 'openai') {
            return $this->available();
        }

        $maximumBytes = (int) data_get($providerConfig, 'constraints.max_file_size_bytes');

        if ($sizeBytes <= $maximumBytes) {
            return $this->available();
        }

        $targetBytes = (int) data_get($providerConfig, 'constraints.transcode_target_bytes');
        $minimumBitrate = (int) data_get(
            $providerConfig,
            'constraints.transcode_min_bitrate_kbps',
        );
        $maximumBitrate = (int) data_get(
            $providerConfig,
            'constraints.transcode_max_bitrate_kbps',
        );
        $requiredBitrate = (int) floor(($targetBytes * 8) / $durationSeconds / 1000);

        if ($requiredBitrate < $minimumBitrate) {
            return $this->unavailable(
                'Para caber no limite da OpenAI, o áudio ficaria abaixo de 16 kbps.',
            );
        }

        return $this->available(
            requiresTranscode: true,
            bitrateKbps: min($maximumBitrate, $requiredBitrate),
        );
    }

    /**
     * @return array{available: true, reason: null, requires_transcode: bool, bitrate_kbps: ?int}
     */
    private function available(
        bool $requiresTranscode = false,
        ?int $bitrateKbps = null,
    ): array {
        return [
            'available' => true,
            'reason' => null,
            'requires_transcode' => $requiresTranscode,
            'bitrate_kbps' => $bitrateKbps,
        ];
    }

    /**
     * @return array{available: false, reason: string, requires_transcode: false, bitrate_kbps: null}
     */
    private function unavailable(string $reason): array
    {
        return [
            'available' => false,
            'reason' => $reason,
            'requires_transcode' => false,
            'bitrate_kbps' => null,
        ];
    }
}
