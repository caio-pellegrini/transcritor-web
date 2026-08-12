<?php

namespace App\Services;

use App\Exceptions\TranscriptionProcessingException;
use Illuminate\Validation\ValidationException;

class TranscriptionDiskSpaceGuard
{
    public function ensureForUpload(): void
    {
        if ($this->hasRequiredSpace(0)) {
            return;
        }

        throw ValidationException::withMessages([
            'media' => 'O servidor está sem espaço livre suficiente para receber o arquivo. Libere espaço e tente novamente.',
        ]);
    }

    public function ensureForProcessing(bool $requiresTranscode): void
    {
        $additionalBytes = $requiresTranscode
            ? (int) config('transcription.providers.openai.constraints.transcode_target_bytes')
            : 0;

        if ($this->hasRequiredSpace($additionalBytes)) {
            return;
        }

        throw new TranscriptionProcessingException(
            'O servidor ficou sem espaço livre suficiente para processar o arquivo. Libere espaço e envie novamente.',
        );
    }

    private function hasRequiredSpace(int $additionalBytes): bool
    {
        $storagePath = storage_path('app');
        clearstatcache(true, $storagePath);
        $availableBytes = @disk_free_space($storagePath);

        if (! is_float($availableBytes)) {
            return false;
        }

        $minimumFreeBytes = (int) config('transcription.storage.minimum_free_bytes');

        return $availableBytes >= $minimumFreeBytes + max(0, $additionalBytes);
    }
}
