<?php

namespace App\Http\Controllers;

use App\Actions\EstimateTranscription;
use App\Models\Transcription;
use App\Services\TranscriptionExportFormatter;
use App\Services\TranscriptionModelAvailability;
use App\Services\UsdBrlExchangeRateService;
use Inertia\Inertia;
use Inertia\Response;

class TranscriptionWorkspaceController extends Controller
{
    public function __invoke(
        EstimateTranscription $estimateTranscription,
        TranscriptionModelAvailability $modelAvailability,
        TranscriptionExportFormatter $exportFormatter,
        UsdBrlExchangeRateService $exchangeRate,
        ?Transcription $transcription = null,
    ): Response {
        return Inertia::render('transcriptions/index', [
            'providers' => config('transcription.providers'),
            'limits' => config('transcription.limits'),
            'exchange_rate_fallback' => [
                'rate' => (float) config('transcription.exchange_rate.fallback'),
                'quoted_at' => now()->toIso8601String(),
                'source' => 'fallback',
            ],
            'exchange_rate' => Inertia::defer(
                fn (): array => $exchangeRate->current(),
                'exchange-rate',
            ),
            'google_oauth_client_id' => config('services.google.oauth_client_id'),
            'transcription' => $transcription === null ? null : [
                'id' => $transcription->id,
                'status' => $transcription->status,
                'progress' => [
                    'stage' => $transcription->progress_stage,
                    'current' => $transcription->progress_current,
                    'total' => $transcription->progress_total,
                ],
                'error_message' => $transcription->error_message,
                'result' => $transcription->status === Transcription::STATUS_COMPLETED
                    ? [
                        'text' => $transcription->text,
                        'language' => $transcription->language,
                        'segments' => $transcription->segments ?? [],
                        'export_text' => $exportFormatter->format($transcription)->plainText(),
                    ]
                    : null,
                'original_filename' => $transcription->original_filename,
                'extension' => $transcription->extension,
                'mime_type' => $transcription->mime_type,
                'size_bytes' => $transcription->size_bytes,
                'duration_seconds' => $transcription->duration_seconds,
                'provider' => $transcription->provider,
                'model' => $transcription->model,
                'diarization' => $transcription->diarization,
                'estimate' => $transcription->status === Transcription::STATUS_AWAITING_CONFIRMATION
                    ? $estimateTranscription->handle($transcription)
                    : null,
                'model_availability' => $modelAvailability->allFor($transcription),
            ],
        ]);
    }
}
