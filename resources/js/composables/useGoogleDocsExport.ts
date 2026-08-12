import { ref } from 'vue';

const DRIVE_FILE_SCOPE = 'https://www.googleapis.com/auth/drive.file';
const GOOGLE_IDENTITY_SCRIPT = 'https://accounts.google.com/gsi/client';
const DRIVE_UPLOAD_ENDPOINT =
    'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id';

interface GoogleTokenResponse {
    access_token?: string;
    error?: string;
}

interface GooglePopupError {
    type: 'popup_failed_to_open' | 'popup_closed' | 'unknown' | string;
}

interface GoogleTokenClient {
    requestAccessToken(): void;
}

interface GoogleAccountsOAuth2 {
    initTokenClient(config: {
        client_id: string;
        scope: string;
        callback: (response: GoogleTokenResponse) => void;
        error_callback: (error: GooglePopupError) => void;
    }): GoogleTokenClient;
}

declare global {
    interface Window {
        google?: {
            accounts: {
                oauth2: GoogleAccountsOAuth2;
            };
        };
    }
}

let scriptPromise: Promise<void> | null = null;

const loadGoogleIdentityServices = (): Promise<void> => {
    if (window.google?.accounts.oauth2) {
        return Promise.resolve();
    }

    if (scriptPromise) {
        return scriptPromise;
    }

    scriptPromise = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = GOOGLE_IDENTITY_SCRIPT;
        script.async = true;
        script.dataset.googleIdentityServices = 'true';
        script.onload = () => resolve();
        script.onerror = () => {
            scriptPromise = null;
            reject(new Error('Não foi possível carregar o acesso ao Google.'));
        };
        document.head.append(script);
    });

    return scriptPromise;
};

const requestAccessToken = (clientId: string): Promise<string> =>
    new Promise((resolve, reject) => {
        const oauth2 = window.google?.accounts.oauth2;

        if (!oauth2) {
            reject(new Error('O acesso ao Google ainda não terminou de carregar.'));

            return;
        }

        let settled = false;
        const finishWithError = (message: string) => {
            if (!settled) {
                settled = true;
                reject(new Error(message));
            }
        };
        const client = oauth2.initTokenClient({
            client_id: clientId,
            scope: DRIVE_FILE_SCOPE,
            callback: (response) => {
                if (settled) {
                    return;
                }

                if (response.error) {
                    finishWithError('A autorização do Google foi cancelada ou recusada.');

                    return;
                }

                if (!response.access_token) {
                    finishWithError('O Google não devolveu um token de acesso válido.');

                    return;
                }

                settled = true;
                resolve(response.access_token);
            },
            error_callback: (error) => {
                if (error.type === 'popup_failed_to_open') {
                    finishWithError(
                        'O navegador bloqueou o popup do Google. Libere popups e tente novamente.',
                    );

                    return;
                }

                if (error.type === 'popup_closed') {
                    finishWithError('A janela do Google foi fechada antes da autorização.');

                    return;
                }

                finishWithError('Não foi possível abrir a autorização do Google.');
            },
        });

        client.requestAccessToken();
    });

const googleDocumentName = (originalFilename: string) => {
    const withoutExtension = originalFilename.replace(/\.[^.]+$/, '').trim();

    return `${withoutExtension || 'Transcrição'} — transcrição`;
};

const multipartBody = (name: string, html: string, boundary: string) =>
    new Blob(
        [
            `--${boundary}\r\n`,
            'Content-Type: application/json; charset=UTF-8\r\n\r\n',
            JSON.stringify({
                name,
                mimeType: 'application/vnd.google-apps.document',
            }),
            `\r\n--${boundary}\r\n`,
            'Content-Type: text/html; charset=UTF-8\r\n\r\n',
            html,
            `\r\n--${boundary}--`,
        ],
        { type: `multipart/related; boundary=${boundary}` },
    );

const driveFailureMessage = (status: number) => {
    if (status === 401) {
        return 'O token do Google expirou. Clique novamente para autorizar e criar o documento.';
    }

    if (status === 403) {
        return 'O Google recusou o acesso à Drive. Confira a permissão drive.file.';
    }

    if (status === 429) {
        return 'A API do Google Drive limitou as requisições. Tente novamente mais tarde.';
    }

    if (status >= 500) {
        return 'O Google Drive está indisponível no momento. Tente novamente mais tarde.';
    }

    return 'Não foi possível criar o documento no Google Drive.';
};

export function useGoogleDocsExport(clientId: string | null) {
    const isReady = ref(false);
    const isCreating = ref(false);
    const error = ref<string | null>(null);
    const successMessage = ref<string | null>(null);
    const documentUrl = ref<string | null>(null);

    const initialize = async () => {
        if (!clientId) {
            error.value = 'Google Docs ainda não foi configurado neste ambiente.';

            return;
        }

        try {
            await loadGoogleIdentityServices();
            isReady.value = true;
        } catch (caught) {
            error.value = caught instanceof Error ? caught.message : 'Falha ao carregar o Google.';
        }
    };

    const createDocument = async (transcriptionId: string, originalFilename: string) => {
        if (!clientId || !isReady.value || isCreating.value) {
            return;
        }

        error.value = null;
        successMessage.value = null;
        documentUrl.value = null;
        isCreating.value = true;
        let accessToken: string | null = null;

        try {
            accessToken = await requestAccessToken(clientId);
            const htmlResponse = await fetch(`/transcriptions/${transcriptionId}/export.html`, {
                credentials: 'same-origin',
                headers: { Accept: 'text/html' },
            });

            if (!htmlResponse.ok) {
                throw new Error('Não foi possível preparar a transcrição para o Google Docs.');
            }

            const html = await htmlResponse.text();
            const boundary = `transcritor_${crypto.randomUUID()}`;
            const driveResponse = await fetch(DRIVE_UPLOAD_ENDPOINT, {
                method: 'POST',
                headers: {
                    Authorization: `Bearer ${accessToken}`,
                    'Content-Type': `multipart/related; boundary=${boundary}`,
                },
                body: multipartBody(googleDocumentName(originalFilename), html, boundary),
            });

            if (!driveResponse.ok) {
                throw new Error(driveFailureMessage(driveResponse.status));
            }

            const payload = (await driveResponse.json()) as { id?: string };

            if (!payload.id) {
                throw new Error('O Google Drive não devolveu o identificador do documento.');
            }

            documentUrl.value = `https://docs.google.com/document/d/${encodeURIComponent(payload.id)}/edit`;
            window.open(documentUrl.value, '_blank', 'noopener,noreferrer');
            successMessage.value =
                'Documento criado no Google Docs. Se a nova aba não abriu, use o link abaixo.';
        } catch (caught) {
            error.value =
                caught instanceof Error ? caught.message : 'Falha ao criar no Google Docs.';
        } finally {
            accessToken = null;
            isCreating.value = false;
        }
    };

    const reset = () => {
        error.value = null;
        successMessage.value = null;
        documentUrl.value = null;
    };

    return {
        createDocument,
        documentUrl,
        error,
        initialize,
        isCreating,
        isReady,
        reset,
        successMessage,
    };
}
