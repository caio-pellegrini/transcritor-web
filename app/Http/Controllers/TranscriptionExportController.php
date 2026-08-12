<?php

namespace App\Http\Controllers;

use App\Data\TranscriptionExportDocument;
use App\Models\Transcription;
use App\Services\TranscriptionExportFormatter;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\Element\TextRun;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TranscriptionExportController extends Controller
{
    public function html(
        Transcription $transcription,
        TranscriptionExportFormatter $formatter,
    ): Response {
        $this->ensureCompleted($transcription);

        return response()
            ->view('exports.transcription', [
                'document' => $formatter->format($transcription),
            ])
            ->withHeaders([
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
            ]);
    }

    public function docx(
        Transcription $transcription,
        TranscriptionExportFormatter $formatter,
    ): StreamedResponse {
        $this->ensureCompleted($transcription);
        $document = $formatter->format($transcription);
        $filename = Str::slug(pathinfo($transcription->original_filename, PATHINFO_FILENAME));
        $filename = ($filename !== '' ? $filename : 'transcricao').'.docx';

        return response()->streamDownload(
            function () use ($document): void {
                IOFactory::createWriter($this->phpWord($document), 'Word2007')
                    ->save('php://output');
            },
            $filename,
            [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'Cache-Control' => 'private, no-store',
            ],
        );
    }

    private function ensureCompleted(Transcription $transcription): void
    {
        abort_unless(
            $transcription->status === Transcription::STATUS_COMPLETED,
            409,
            'A transcrição precisa estar concluída para ser exportada.',
        );
    }

    private function phpWord(TranscriptionExportDocument $document): PhpWord
    {
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(11);
        $section = $phpWord->addSection();
        $section->addTitle($document->title, 1);

        foreach ($document->metadata as $metadata) {
            $run = $section->addTextRun(['spaceAfter' => 60]);
            $run->addText($metadata['label'].': ', ['bold' => true]);
            $run->addText($metadata['value']);
        }

        $section->addTextBreak();
        $section->addTitle('Transcrição', 2);

        foreach ($document->paragraphs as $paragraph) {
            $run = $section->addTextRun(['spaceAfter' => 120]);

            if ($paragraph['speaker_label'] !== null) {
                $run->addText($paragraph['speaker_label'], ['bold' => true]);
                $run->addText(' ');
            }

            $this->addLines($run, $paragraph['text_lines']);

            if ($paragraph['time_label'] !== null) {
                $run->addText(' '.$paragraph['time_label']);
            }
        }

        return $phpWord;
    }

    /**
     * @param  list<string>  $lines
     */
    private function addLines(TextRun $run, array $lines): void
    {
        foreach ($lines as $index => $line) {
            if ($index > 0) {
                $run->addTextBreak();
            }

            $run->addText($line);
        }
    }
}
