<?php

namespace App\Actions;

use App\Models\Transcription;
use App\Services\MediaProbeService;
use App\Services\TranscriptionDiskSpaceGuard;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateTranscription
{
    public function __construct(
        private readonly MediaProbeService $mediaProbe,
        private readonly TranscriptionDiskSpaceGuard $diskSpace,
    ) {}

    public function handle(
        UploadedFile $file,
        string $provider,
        string $model,
        bool $diarization,
    ): Transcription {
        $extension = strtolower($file->getClientOriginalExtension());
        $metadata = $this->mediaProbe->inspect($file, $extension);
        $this->diskSpace->ensureForUpload();
        $id = (string) Str::ulid();
        $mediaPath = "transcriptions/{$id}/source.{$extension}";
        $directory = "transcriptions/{$id}";
        $originalFilename = Str::limit($file->getClientOriginalName(), 255, '');
        $mimeType = (string) $file->getMimeType();
        $sizeBytes = (int) $file->getSize();

        try {
            if (! Storage::disk('local')->makeDirectory($directory)) {
                throw new \RuntimeException('Could not create transcription directory.');
            }

            $file->move(
                Storage::disk('local')->path($directory),
                "source.{$extension}",
            );

            return Transcription::query()->create([
                'id' => $id,
                'status' => Transcription::STATUS_AWAITING_CONFIRMATION,
                'original_filename' => $originalFilename,
                'media_path' => $mediaPath,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'size_bytes' => $sizeBytes,
                'duration_seconds' => $metadata['duration_seconds'],
                'provider' => $provider,
                'model' => $model,
                'diarization' => $diarization,
                'expires_at' => now()->addHours(
                    (int) config('transcription.cleanup.awaiting_confirmation_hours'),
                ),
            ]);
        } catch (Throwable) {
            Storage::disk('local')->deleteDirectory($directory);

            throw ValidationException::withMessages([
                'media' => 'Não foi possível guardar o arquivo no servidor. Verifique o espaço em disco e tente novamente.',
            ]);
        }
    }
}
