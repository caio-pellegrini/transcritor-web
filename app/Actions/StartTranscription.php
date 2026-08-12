<?php

namespace App\Actions;

use App\Jobs\ProcessTranscriptionJob;
use App\Models\Transcription;
use App\Services\TranscriptionModelAvailability;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StartTranscription
{
    public function __construct(
        private readonly TranscriptionModelAvailability $modelAvailability,
    ) {}

    public function handle(Transcription $transcription, string $encryptedApiKey): bool
    {
        if ($transcription->status === Transcription::STATUS_AWAITING_CONFIRMATION) {
            $availability = $this->modelAvailability->forTranscription($transcription);

            if (! $availability['available']) {
                throw ValidationException::withMessages([
                    'transcription' => $availability['reason'],
                ]);
            }
        }

        $claimed = DB::transaction(fn (): bool => Transcription::query()
            ->whereKey($transcription->getKey())
            ->where('status', Transcription::STATUS_AWAITING_CONFIRMATION)
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->update([
                'status' => Transcription::STATUS_QUEUED,
                'progress_stage' => 'queued',
                'progress_current' => null,
                'progress_total' => null,
                'error_message' => null,
                'expires_at' => null,
                'queued_at' => now(),
                'updated_at' => now(),
            ]) === 1);

        if ($claimed) {
            ProcessTranscriptionJob::dispatch($transcription->getKey(), $encryptedApiKey);
        }

        return $claimed;
    }
}
