<?php

namespace App\Actions;

use App\Models\Transcription;
use App\Services\TranscriptionModelAvailability;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UpdateTranscriptionEstimate
{
    public function __construct(
        private readonly TranscriptionModelAvailability $modelAvailability,
    ) {}

    public function handle(
        Transcription $transcription,
        string $provider,
        string $model,
        bool $diarization,
    ): Transcription {
        if ($transcription->status !== Transcription::STATUS_AWAITING_CONFIRMATION) {
            throw new ConflictHttpException('Esta transcrição não aceita mais alterações.');
        }

        $availability = $this->modelAvailability->forValues(
            provider: $provider,
            model: $model,
            durationSeconds: $transcription->duration_seconds,
            sizeBytes: $transcription->size_bytes,
        );

        if (! $availability['available']) {
            throw ValidationException::withMessages([
                'model' => $availability['reason'],
            ]);
        }

        $transcription->update([
            'provider' => $provider,
            'model' => $model,
            'diarization' => $diarization,
        ]);

        return $transcription->refresh();
    }
}
