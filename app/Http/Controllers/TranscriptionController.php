<?php

namespace App\Http\Controllers;

use App\Actions\CreateTranscription;
use App\Actions\StartTranscription;
use App\Actions\UpdateTranscriptionEstimate;
use App\Http\Requests\StoreTranscriptionRequest;
use App\Http\Requests\UpdateTranscriptionEstimateRequest;
use App\Models\Transcription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

class TranscriptionController extends Controller
{
    public function store(
        StoreTranscriptionRequest $request,
        CreateTranscription $createTranscription,
    ): RedirectResponse {
        $file = $request->file('media');

        abort_unless($file instanceof UploadedFile, 422);

        $transcription = $createTranscription->handle(
            file: $file,
            provider: $request->validated('provider'),
            model: $request->validated('model'),
            diarization: $request->boolean('diarization'),
        );

        return to_route('transcriptions.show', $transcription);
    }

    public function updateEstimate(
        UpdateTranscriptionEstimateRequest $request,
        Transcription $transcription,
        UpdateTranscriptionEstimate $updateTranscriptionEstimate,
    ): RedirectResponse {
        $updateTranscriptionEstimate->handle(
            transcription: $transcription,
            provider: $request->validated('provider'),
            model: $request->validated('model'),
            diarization: $request->boolean('diarization'),
        );

        return to_route('transcriptions.show', $transcription);
    }

    public function start(
        Request $request,
        Transcription $transcription,
        StartTranscription $startTranscription,
    ): RedirectResponse {
        $apiKey = $request->header('X-Transcription-Api-Key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw ValidationException::withMessages([
                'api_key' => 'Informe a API key do provider antes de iniciar.',
            ]);
        }

        $encryptedApiKey = Crypt::encryptString($apiKey);
        unset($apiKey);

        try {
            $started = $startTranscription->handle($transcription, $encryptedApiKey);
        } finally {
            unset($encryptedApiKey);
        }

        if (! $started && $transcription->fresh()?->status === Transcription::STATUS_AWAITING_CONFIRMATION) {
            throw ValidationException::withMessages([
                'transcription' => 'Esta estimativa expirou. Envie o arquivo novamente.',
            ]);
        }

        return to_route('transcriptions.show', $transcription);
    }
}
