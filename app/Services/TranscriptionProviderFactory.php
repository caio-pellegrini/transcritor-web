<?php

namespace App\Services;

use App\Contracts\TranscriptionProvider;
use App\Exceptions\TranscriptionProcessingException;

class TranscriptionProviderFactory
{
    public function __construct(
        private readonly OpenAiTranscriptionProvider $openAi,
        private readonly ElevenLabsTranscriptionProvider $elevenLabs,
    ) {}

    public function for(string $provider): TranscriptionProvider
    {
        return match ($provider) {
            'openai' => $this->openAi,
            'elevenlabs' => $this->elevenLabs,
            default => throw new TranscriptionProcessingException(
                'O provider selecionado não é suportado.',
            ),
        };
    }
}
