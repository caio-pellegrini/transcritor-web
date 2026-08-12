<?php

namespace App\Contracts;

use App\Data\TranscriptionResult;
use App\Models\Transcription;

interface TranscriptionProvider
{
    public function transcribe(
        Transcription $transcription,
        string $mediaPath,
        string $apiKey,
    ): TranscriptionResult;
}
