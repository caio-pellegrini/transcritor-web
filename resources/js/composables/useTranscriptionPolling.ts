import { onUnmounted, ref } from 'vue';

export type TranscriptionStatus =
    'awaiting_confirmation' | 'queued' | 'processing' | 'completed' | 'failed' | 'expired';

export interface TranscriptionProgress {
    stage: string | null;
    current: number | null;
    total: number | null;
}

export interface TranscriptionSegment {
    text: string;
    speaker: string | null;
    start: number | null;
    end: number | null;
}

export interface TranscriptionResult {
    text: string;
    language: string | null;
    segments: TranscriptionSegment[];
    export_text: string;
}

export interface TranscriptionStatusResponse {
    id: string;
    status: TranscriptionStatus;
    progress: TranscriptionProgress;
    error_message: string | null;
    result: TranscriptionResult | null;
}

const activeStatuses: TranscriptionStatus[] = ['queued', 'processing'];

export function useTranscriptionPolling(
    onUpdate: (response: TranscriptionStatusResponse) => void,
    intervalMilliseconds = 2500,
) {
    const isPolling = ref(false);
    let timeoutId: number | undefined;
    let runId = 0;

    const stop = () => {
        runId++;
        isPolling.value = false;

        if (timeoutId !== undefined) {
            window.clearTimeout(timeoutId);
            timeoutId = undefined;
        }
    };

    const requestStatus = async (transcriptionId: string, currentRunId: number) => {
        try {
            const response = await fetch(`/transcriptions/${transcriptionId}/status`, {
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                },
            });

            if (!response.ok) {
                throw new Error('Não foi possível consultar o andamento.');
            }

            const status = (await response.json()) as TranscriptionStatusResponse;

            if (currentRunId !== runId) {
                return;
            }

            onUpdate(status);

            if (!activeStatuses.includes(status.status)) {
                stop();

                return;
            }
        } catch {
            if (currentRunId !== runId) {
                return;
            }
        }

        timeoutId = window.setTimeout(
            () => void requestStatus(transcriptionId, currentRunId),
            intervalMilliseconds,
        );
    };

    const start = (transcriptionId: string, status: TranscriptionStatus) => {
        stop();

        if (!activeStatuses.includes(status)) {
            return;
        }

        isPolling.value = true;
        const currentRunId = runId;
        void requestStatus(transcriptionId, currentRunId);
    };

    onUnmounted(stop);

    return {
        isPolling,
        start,
        stop,
    };
}
