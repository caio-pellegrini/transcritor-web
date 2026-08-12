<?php

namespace App\Data;

final readonly class TranscriptionResult
{
    /**
     * @param  list<array{text: string, speaker: ?string, start: ?float, end: ?float}>  $segments
     */
    public function __construct(
        public string $text,
        public ?string $language,
        public array $segments,
    ) {}
}
