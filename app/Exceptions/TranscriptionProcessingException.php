<?php

namespace App\Exceptions;

use RuntimeException;

class TranscriptionProcessingException extends RuntimeException
{
    public function __construct(
        public readonly string $userMessage,
    ) {
        parent::__construct('Transcription processing failed.');
    }
}
