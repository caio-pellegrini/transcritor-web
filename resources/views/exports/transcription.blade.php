<!DOCTYPE html>
<html lang="pt-BR">
    <head>
        <meta charset="utf-8">
        <title>{{ $document->title }}</title>
    </head>
    <body>
        <h1>{{ $document->title }}</h1>

        @foreach ($document->metadata as $metadata)
            <p><b>{{ $metadata['label'] }}:</b> {{ $metadata['value'] }}</p>
        @endforeach

        <h2>Transcrição</h2>

        @foreach ($document->paragraphs as $paragraph)
            <p>
                @if ($paragraph['speaker_label'] !== null)<b>{{ $paragraph['speaker_label'] }}</b> @endif
                @foreach ($paragraph['text_lines'] as $index => $line)@if ($index > 0)<br>@endif{{ $line }}@endforeach
                @if ($paragraph['time_label'] !== null) {{ $paragraph['time_label'] }}@endif
            </p>
        @endforeach
    </body>
</html>
