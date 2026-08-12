<?php

namespace App\Data;

final readonly class TranscriptionExportDocument
{
    /**
     * @param  list<array{label: string, value: string}>  $metadata
     * @param  list<array{speaker_label: ?string, text: string, text_lines: list<string>, time_label: ?string, plain_text: string}>  $paragraphs
     */
    public function __construct(
        public string $title,
        public array $metadata,
        public array $paragraphs,
    ) {}

    public function plainText(): string
    {
        return implode(PHP_EOL, array_column($this->paragraphs, 'plain_text'));
    }
}
