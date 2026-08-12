<?php

use Symfony\Component\Finder\Finder;

test('vue templates never render untrusted html', function () {
    $vueFiles = Finder::create()
        ->files()
        ->in(__DIR__.'/../../resources/js')
        ->name('*.vue');

    foreach ($vueFiles as $vueFile) {
        expect($vueFile->getContents())->not->toContain('v-html');
    }
});

test('production content security policy permits only local and approved google scripts', function () {
    $nginxConfig = file_get_contents(__DIR__.'/../../docker/nginx/default.conf');

    expect($nginxConfig)
        ->toContain("script-src 'self' 'nonce-\$request_id' https://accounts.google.com https://apis.google.com https://www.googleapis.com")
        ->toContain("style-src 'self' https://accounts.google.com")
        ->toContain("media-src 'self' blob:")
        ->toContain("connect-src 'self' https://accounts.google.com https://apis.google.com https://www.googleapis.com https://oauth2.googleapis.com")
        ->toContain('Cross-Origin-Opener-Policy "same-origin-allow-popups"')
        ->toContain('fastcgi_param CSP_NONCE $request_id')
        ->not->toContain("script-src 'unsafe-inline'")
        ->not->toContain("script-src 'unsafe-eval'")
        ->not->toContain("style-src 'unsafe-inline'");
});

test('polling is recursive and stops when a transcription reaches a terminal state', function () {
    $polling = file_get_contents(
        __DIR__.'/../../resources/js/composables/useTranscriptionPolling.ts',
    );

    expect($polling)
        ->toContain('window.setTimeout')
        ->toContain('if (!activeStatuses.includes(status.status))')
        ->toContain('stop();')
        ->not->toContain('setInterval');
});

test('estimate interface visibly disables unavailable models and identifies its recommendation', function () {
    $page = file_get_contents(
        __DIR__.'/../../resources/js/pages/transcriptions/index.vue',
    );

    expect($page)
        ->toContain('!availabilityFor(selectedProviderId, modelId).available')
        ->toContain('Recomendado para este arquivo:')
        ->toContain('Acima de 25 minutos, a diarização fica disponível somente na')
        ->not->toContain('v-html');
});

test('local preview reads only browser metadata and keeps upload separate from api key delivery', function () {
    $page = file_get_contents(
        __DIR__.'/../../resources/js/pages/transcriptions/index.vue',
    );
    $preview = file_get_contents(
        __DIR__.'/../../resources/js/composables/useLocalTranscriptionPreview.ts',
    );

    expect($preview)
        ->toContain("media.preload = 'metadata'")
        ->toContain('URL.createObjectURL(file)')
        ->toContain('URL.revokeObjectURL(objectUrl)')
        ->toContain('Math.max(preview.cost_usd * 0.05, 0.05)')
        ->toContain('preview.availability.requires_transcode !==')
        ->toContain('preview.duration_seconds <= 1500 !== confirmed.duration_seconds <= 1500')
        ->not->toContain('FileReader');

    expect($page)
        ->toContain("uploadForm.post('/transcriptions'")
        ->toContain("'X-Transcription-Api-Key': key")
        ->toContain('startTranscription(transcription.id, keyForStart)')
        ->toContain('Prévia local — nenhum upload feito')
        ->toContain('Confirmar valor corrigido e iniciar')
        ->toContain("new Intl.NumberFormat('pt-BR'")
        ->toContain('maximumFractionDigits: 2')
        ->not->toContain('v-html');
});

test('completed interface exports without rendering provider html or sending google tokens to the backend', function () {
    $page = file_get_contents(
        __DIR__.'/../../resources/js/pages/transcriptions/index.vue',
    );
    $googleExport = file_get_contents(
        __DIR__.'/../../resources/js/composables/useGoogleDocsExport.ts',
    );

    expect($page)
        ->toContain('navigator.clipboard.writeText(liveResult.value.export_text)')
        ->toContain('/export.docx')
        ->toContain('Criar no Google Docs')
        ->toContain('Iniciar nova transcrição')
        ->not->toContain('v-html');

    expect($googleExport)
        ->toContain("const DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file'")
        ->toContain('uploadType=multipart')
        ->toContain('multipart/related')
        ->toContain("window.open(documentUrl.value, '_blank', 'noopener,noreferrer')")
        ->toContain('popup_failed_to_open')
        ->toContain('popup_closed')
        ->not->toContain('https://www.googleapis.com/auth/drive\'')
        ->not->toContain('docs.googleapis.com');
});
