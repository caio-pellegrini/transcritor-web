export interface PreviewPricing {
    type: 'per_minute' | 'per_hour';
    usd: number;
    minimum_minutes?: number;
    minimum_hours?: number | null;
}

export interface PreviewModel {
    capabilities: { diarization: boolean };
    constraints?: { max_duration_seconds?: number | null };
    pricing: PreviewPricing;
}

export interface PreviewProvider {
    constraints: {
        max_file_size_bytes: number;
        max_duration_seconds?: number | null;
        transcode_target_bytes?: number;
        transcode_min_bitrate_kbps?: number;
        transcode_max_bitrate_kbps?: number;
    };
    models: Record<string, PreviewModel>;
}

export interface PreviewAvailability {
    available: boolean;
    reason: string | null;
    requires_transcode: boolean;
    bitrate_kbps: number | null;
}

export interface PreviewComparison {
    duration_seconds: number;
    cost_usd: number;
    availability: PreviewAvailability;
    diarization_valid: boolean;
}

const available = (
    requiresTranscode = false,
    bitrateKbps: number | null = null,
): PreviewAvailability => ({
    available: true,
    reason: null,
    requires_transcode: requiresTranscode,
    bitrate_kbps: bitrateKbps,
});

const unavailable = (reason: string): PreviewAvailability => ({
    available: false,
    reason,
    requires_transcode: false,
    bitrate_kbps: null,
});

export const calculateAvailability = (
    providers: Record<string, PreviewProvider>,
    providerId: string,
    modelId: string,
    durationSeconds: number,
    sizeBytes: number,
): PreviewAvailability => {
    const provider = providers[providerId];
    const model = provider?.models[modelId];

    if (!provider || !model) {
        return unavailable('Provider ou modelo inválido.');
    }

    const modelDurationLimit = model.constraints?.max_duration_seconds;

    if (modelDurationLimit && durationSeconds > modelDurationLimit) {
        return unavailable('Este modelo não aceita a duração deste áudio.');
    }

    const providerDurationLimit = provider.constraints.max_duration_seconds;

    if (providerDurationLimit && durationSeconds > providerDurationLimit) {
        return unavailable('A duração excede o limite deste provider.');
    }

    if (providerId !== 'openai') {
        return available();
    }

    if (sizeBytes <= provider.constraints.max_file_size_bytes) {
        return available();
    }

    const targetBytes = provider.constraints.transcode_target_bytes ?? 0;
    const minimumBitrate = provider.constraints.transcode_min_bitrate_kbps ?? 0;
    const maximumBitrate = provider.constraints.transcode_max_bitrate_kbps ?? 0;
    const requiredBitrate = Math.floor((targetBytes * 8) / durationSeconds / 1000);

    if (requiredBitrate < minimumBitrate) {
        return unavailable('Para caber no limite da OpenAI, o áudio ficaria abaixo de 16 kbps.');
    }

    return available(true, Math.min(maximumBitrate, requiredBitrate));
};

export const estimateCostUsd = (pricing: PreviewPricing, durationSeconds: number): number => {
    const durationMinutes = durationSeconds / 60;
    const cost =
        pricing.type === 'per_hour'
            ? Math.max(durationSeconds / 3600, pricing.minimum_hours ?? 0) * pricing.usd
            : Math.max(durationMinutes, pricing.minimum_minutes ?? 0) * pricing.usd;

    return Number(cost.toFixed(6));
};

export const confirmationReason = (
    preview: PreviewComparison,
    confirmed: PreviewComparison,
): string | null => {
    if (preview.availability.available !== confirmed.availability.available) {
        return 'A validação do servidor alterou a disponibilidade da opção escolhida.';
    }

    if (!confirmed.availability.available) {
        return confirmed.availability.reason ?? 'A opção escolhida não serve para este arquivo.';
    }

    if (preview.availability.requires_transcode !== confirmed.availability.requires_transcode) {
        return 'A validação do servidor alterou a necessidade de conversão do arquivo.';
    }

    if (preview.diarization_valid !== confirmed.diarization_valid || !confirmed.diarization_valid) {
        return 'A diarização escolhida não é válida para a duração confirmada.';
    }

    const increase = confirmed.cost_usd - preview.cost_usd;
    const meaningfulIncrease = Math.max(preview.cost_usd * 0.05, 0.05);

    if (increase > meaningfulIncrease) {
        return 'O custo em USD confirmado ficou relevantemente acima da prévia.';
    }

    return null;
};

export const readLocalMediaDuration = (file: File, timeoutMilliseconds = 10_000) =>
    new Promise<number>((resolve, reject) => {
        const media = document.createElement('video');
        const objectUrl = URL.createObjectURL(file);
        let settled = false;

        const cleanup = () => {
            window.clearTimeout(timeout);
            media.onloadedmetadata = null;
            media.onerror = null;
            media.removeAttribute('src');
            media.load();
            URL.revokeObjectURL(objectUrl);
        };

        const finish = (duration?: number) => {
            if (settled) return;
            settled = true;
            cleanup();

            if (duration !== undefined && Number.isFinite(duration) && duration > 0) {
                resolve(duration);
                return;
            }

            reject(new Error('Não foi possível ler a duração localmente.'));
        };

        const timeout = window.setTimeout(() => finish(), timeoutMilliseconds);
        media.preload = 'metadata';
        media.onloadedmetadata = () => finish(media.duration);
        media.onerror = () => finish();
        media.src = objectUrl;
    });
