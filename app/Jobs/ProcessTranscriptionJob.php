<?php

namespace App\Jobs;

use App\Exceptions\TranscriptionProcessingException;
use App\Models\Transcription;
use App\Services\PrepareTranscriptionMedia;
use App\Services\TranscriptionDiskSpaceGuard;
use App\Services\TranscriptionModelAvailability;
use App\Services\TranscriptionProviderFactory;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ProcessTranscriptionJob implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 25200;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly string $transcriptionId,
        public readonly string $encryptedApiKey,
    ) {}

    public function handle(
        TranscriptionModelAvailability $modelAvailability,
        TranscriptionDiskSpaceGuard $diskSpace,
        PrepareTranscriptionMedia $prepareMedia,
        TranscriptionProviderFactory $providers,
    ): void {
        $transcription = Transcription::query()->find($this->transcriptionId);

        if ($transcription === null || $transcription->status !== Transcription::STATUS_QUEUED) {
            return;
        }

        $apiKey = null;

        try {
            $apiKey = Crypt::decryptString($this->encryptedApiKey);
            $availability = $modelAvailability->forTranscription($transcription);

            if (! $availability['available']) {
                throw new TranscriptionProcessingException(
                    $availability['reason'] ?? 'O modelo não está disponível para este arquivo.',
                );
            }

            $diskSpace->ensureForProcessing($availability['requires_transcode']);

            $transcription->update([
                'status' => Transcription::STATUS_PROCESSING,
                'progress_stage' => 'preparing',
                'progress_current' => null,
                'progress_total' => null,
                'started_at' => now(),
            ]);

            $mediaPath = $prepareMedia->handle($transcription, $availability);

            $transcription->update([
                'progress_stage' => 'transcribing',
                'progress_current' => null,
                'progress_total' => null,
            ]);

            $result = $providers
                ->for($transcription->provider)
                ->transcribe($transcription, $mediaPath, $apiKey);

            $transcription->update([
                'progress_stage' => 'finalizing',
            ]);

            $this->cleanupMedia($transcription);

            $transcription->update([
                'status' => Transcription::STATUS_COMPLETED,
                'progress_stage' => 'completed',
                'text' => $result->text,
                'language' => $result->language,
                'segments' => $result->segments,
                'finished_at' => now(),
            ]);
        } catch (TranscriptionProcessingException $exception) {
            $this->cleanupMedia($transcription);
            $this->failTranscription($transcription, $exception->userMessage);
        } catch (Throwable) {
            $this->cleanupMedia($transcription);
            $this->failTranscription(
                $transcription,
                'Não foi possível concluir a transcrição.',
            );
        } finally {
            unset($apiKey);
            $this->cleanupMedia($transcription);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $transcription = Transcription::query()->find($this->transcriptionId);

        if ($transcription === null || in_array($transcription->status, [
            Transcription::STATUS_COMPLETED,
            Transcription::STATUS_FAILED,
            Transcription::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        $this->cleanupMedia($transcription);
        $this->failTranscription(
            $transcription,
            'O processamento excedeu o limite operacional. Envie o arquivo novamente.',
        );
    }

    private function failTranscription(Transcription $transcription, string $message): void
    {
        $transcription->update([
            'status' => Transcription::STATUS_FAILED,
            'progress_stage' => 'failed',
            'error_message' => $message,
            'finished_at' => now(),
        ]);
    }

    private function cleanupMedia(Transcription $transcription): void
    {
        Storage::disk('local')->deleteDirectory("transcriptions/{$transcription->getKey()}");
    }
}
