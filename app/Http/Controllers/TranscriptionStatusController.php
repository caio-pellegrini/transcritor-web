<?php

namespace App\Http\Controllers;

use App\Models\Transcription;
use App\Services\TranscriptionExportFormatter;
use Illuminate\Http\JsonResponse;

class TranscriptionStatusController extends Controller
{
    public function __invoke(
        Transcription $transcription,
        TranscriptionExportFormatter $exportFormatter,
    ): JsonResponse {
        return response()->json([
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
        ]);
    }
}
