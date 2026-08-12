<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { useGoogleDocsExport } from '@/composables/useGoogleDocsExport';
import {
    calculateAvailability,
    confirmationReason,
    estimateCostUsd,
    readLocalMediaDuration,
    type PreviewComparison,
} from '@/composables/useLocalTranscriptionPreview';
import {
    type TranscriptionProgress,
    type TranscriptionResult,
    type TranscriptionStatus,
    type TranscriptionStatusResponse,
    useTranscriptionPolling,
} from '@/composables/useTranscriptionPolling';

interface Pricing {
    type: 'per_minute' | 'per_hour';
    usd: number;
    minimum_minutes?: number;
    minimum_hours?: number | null;
}

interface TranscriptionModel {
    label: string;
    display_order: number;
    capabilities: {
        diarization: boolean;
    };
    constraints?: {
        max_duration_seconds?: number | null;
    };
    pricing: Pricing;
}

interface ModelAvailability {
    available: boolean;
    reason: string | null;
    requires_transcode: boolean;
    bitrate_kbps: number | null;
}

interface Provider {
    label: string;
    display_order: number;
    constraints: {
        max_file_size_bytes: number;
        max_duration_seconds?: number | null;
        transcode_target_bytes?: number;
        transcode_min_bitrate_kbps?: number;
        transcode_max_bitrate_kbps?: number;
        accepted_extensions: string[];
    };
    models: Record<string, TranscriptionModel>;
    pricing_notice: string;
}

interface TranscriptionEstimate {
    cost_usd: number;
    cost_brl: number;
    exchange_rate: number;
    exchange_rate_quoted_at: string;
    exchange_rate_source: 'awesomeapi' | 'fallback';
    approximate_processing_seconds: number;
    pricing_notice: string;
}

interface Transcription {
    id: string;
    status: TranscriptionStatus;
    progress: TranscriptionProgress;
    error_message: string | null;
    result: TranscriptionResult | null;
    original_filename: string;
    extension: string;
    mime_type: string;
    size_bytes: number;
    duration_seconds: number;
    provider: string;
    model: string;
    diarization: boolean;
    estimate: TranscriptionEstimate | null;
    model_availability: Record<string, Record<string, ModelAvailability>>;
    recommended_provider: string;
}

const props = defineProps<{
    providers: Record<string, Provider>;
    limits: {
        max_upload_bytes: number;
        max_duration_seconds: number;
    };
    exchange_rate?: {
        rate: number;
        quoted_at: string;
        source: 'awesomeapi' | 'fallback';
    };
    exchange_rate_fallback: {
        rate: number;
        quoted_at: string;
        source: 'fallback';
    };
    transcription: Transcription | null;
    google_oauth_client_id: string | null;
}>();

const providerIds = Object.keys(props.providers).sort(
    (left, right) => props.providers[left].display_order - props.providers[right].display_order,
);
const selectedProviderId = ref(props.transcription?.provider ?? providerIds[0] ?? '');
const selectedProvider = computed(() => props.providers[selectedProviderId.value]);
const orderedModelIds = (providerId: string) =>
    Object.keys(props.providers[providerId]?.models ?? {}).sort(
        (left, right) =>
            props.providers[providerId].models[left].display_order -
            props.providers[providerId].models[right].display_order,
    );
const selectedModelId = ref(
    props.transcription?.model ?? orderedModelIds(selectedProviderId.value)[0] ?? '',
);
const selectedModel = computed(() => selectedProvider.value?.models[selectedModelId.value]);
const selectedModelIds = computed(() => orderedModelIds(selectedProviderId.value));
const diarization = ref(props.transcription?.diarization ?? false);
const apiKey = ref('');
const localDuration = ref<number | null>(null);
const localDurationState = ref<'idle' | 'loading' | 'ready' | 'unavailable'>('idle');
const localDurationError = ref<string | null>(null);
const previewAtUpload = ref<PreviewComparison | null>(null);
const confirmationMessage = ref<string | null>(null);
const automaticStartPending = ref(false);
let selectedFileSequence = 0;
const liveStatus = ref<TranscriptionStatus>(props.transcription?.status ?? 'awaiting_confirmation');
const liveProgress = ref<TranscriptionProgress>(
    props.transcription?.progress ?? { stage: null, current: null, total: null },
);
const liveError = ref<string | null>(props.transcription?.error_message ?? null);
const liveResult = ref<TranscriptionResult | null>(props.transcription?.result ?? null);

const uploadForm = useForm<{
    media: File | null;
    provider: string;
    model: string;
    diarization: boolean;
}>({
    media: null,
    provider: selectedProviderId.value,
    model: selectedModelId.value,
    diarization: diarization.value,
});

const estimateForm = useForm({
    provider: selectedProviderId.value,
    model: selectedModelId.value,
    diarization: diarization.value,
});

const startProcessing = ref(false);
const startErrors = ref<Record<string, string>>({});
const clipboardMessage = ref<string | null>(null);
const clipboardError = ref<string | null>(null);
const {
    createDocument: createGoogleDocument,
    documentUrl: googleDocumentUrl,
    error: googleError,
    initialize: initializeGoogle,
    isCreating: isCreatingGoogleDocument,
    isReady: isGoogleReady,
    reset: resetGoogleExport,
    successMessage: googleSuccessMessage,
} = useGoogleDocsExport(props.google_oauth_client_id);

const applyStatus = (response: TranscriptionStatusResponse) => {
    liveStatus.value = response.status;
    liveProgress.value = response.progress;
    liveError.value = response.error_message;
    liveResult.value = response.result;
};

const { isPolling, start: startPolling } = useTranscriptionPolling(applyStatus);

const apiKeyStorageKey = (providerId: string) => `transcritor.api-key.${providerId}`;

const loadApiKey = () => {
    apiKey.value = localStorage.getItem(apiKeyStorageKey(selectedProviderId.value)) ?? '';
};

const updateApiKey = (event: Event) => {
    const value = (event.target as HTMLInputElement).value;
    apiKey.value = value;

    if (value === '') {
        localStorage.removeItem(apiKeyStorageKey(selectedProviderId.value));
        return;
    }

    localStorage.setItem(apiKeyStorageKey(selectedProviderId.value), value);
};

const selectFile = async (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    const sequence = ++selectedFileSequence;
    uploadForm.media = file;
    uploadForm.clearErrors('media');
    localDuration.value = null;
    localDurationError.value = null;
    confirmationMessage.value = null;

    if (!file) {
        localDurationState.value = 'idle';
        return;
    }

    if (file.size > props.limits.max_upload_bytes) {
        localDurationState.value = 'unavailable';
        localDurationError.value = `O arquivo excede o limite de ${formatBytes(props.limits.max_upload_bytes)}.`;
        return;
    }

    localDurationState.value = 'loading';

    try {
        const duration = await readLocalMediaDuration(file);

        if (sequence !== selectedFileSequence) return;

        localDuration.value = duration;
        localDurationState.value = 'ready';

        if (duration > props.limits.max_duration_seconds) {
            localDurationError.value = 'O arquivo excede o limite de duração de 4 horas.';
        }
    } catch {
        if (sequence !== selectedFileSequence) return;

        localDurationState.value = 'unavailable';
        localDurationError.value =
            'O navegador não conseguiu ler a duração. O servidor pode validar o arquivo após o envio.';
    }
};

const submitUpload = () => {
    startErrors.value = {};
    confirmationMessage.value = null;

    if (apiKey.value.trim() === '') {
        startErrors.value.api_key = 'Informe a API key do provider antes de iniciar.';
        return;
    }

    if (localDuration.value && localDuration.value > props.limits.max_duration_seconds) {
        uploadForm.setError('media', 'O arquivo excede o limite de duração de 4 horas.');
        return;
    }

    if (!selectedAvailability.value.available) {
        uploadForm.setError(
            'model',
            selectedAvailability.value.reason ?? 'Esta opção não serve para o arquivo.',
        );
        return;
    }

    uploadForm.provider = selectedProviderId.value;
    uploadForm.model = selectedModelId.value;
    uploadForm.diarization = diarization.value;

    const keyForStart = apiKey.value;
    const preview = localPreviewComparison.value;
    previewAtUpload.value = preview;
    automaticStartPending.value = preview !== null;

    uploadForm.post('/transcriptions', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: (page) => {
            uploadForm.reset('media');
            const transcription = page.props.transcription as Transcription | null;

            if (!transcription?.estimate) {
                automaticStartPending.value = false;
                return;
            }

            if (!preview) {
                automaticStartPending.value = false;
                confirmationMessage.value =
                    'A duração foi calculada pelo servidor. Confira o valor antes de iniciar.';
                return;
            }

            const confirmedAvailability =
                transcription.model_availability[transcription.provider]?.[transcription.model];
            const reason = confirmationReason(preview, {
                duration_seconds: transcription.duration_seconds,
                cost_usd: transcription.estimate.cost_usd,
                availability: confirmedAvailability,
                diarization_valid:
                    !transcription.diarization ||
                    (props.providers[transcription.provider]?.models[transcription.model]
                        ?.capabilities.diarization ??
                        false),
            });

            if (reason) {
                automaticStartPending.value = false;
                confirmationMessage.value = reason;
                return;
            }

            startTranscription(transcription.id, keyForStart);
        },
        onError: () => {
            automaticStartPending.value = false;
        },
    });
};

const recalculateEstimate = () => {
    if (!props.transcription) {
        return;
    }

    estimateForm.provider = selectedProviderId.value;
    estimateForm.model = selectedModelId.value;
    estimateForm.diarization = diarization.value;

    estimateForm.patch(`/transcriptions/${props.transcription.id}/estimate`, {
        preserveScroll: true,
    });
};

const startTranscription = (transcriptionId = props.transcription?.id, key = apiKey.value) => {
    if (!transcriptionId) {
        return;
    }

    startErrors.value = {};

    if (key.trim() === '') {
        startErrors.value.api_key = 'Informe a API key do provider antes de iniciar.';

        return;
    }

    router.post(
        `/transcriptions/${transcriptionId}/start`,
        {},
        {
            headers: {
                'X-Transcription-Api-Key': key,
            },
            preserveScroll: true,
            onStart: () => {
                startProcessing.value = true;
            },
            onError: (errors) => {
                startErrors.value = errors;
            },
            onFinish: () => {
                startProcessing.value = false;
                automaticStartPending.value = false;
            },
        },
    );
};

const copyTranscription = async () => {
    if (!liveResult.value) {
        return;
    }

    clipboardMessage.value = null;
    clipboardError.value = null;

    try {
        await navigator.clipboard.writeText(liveResult.value.export_text);
        clipboardMessage.value = 'Transcrição copiada para a área de transferência.';
    } catch {
        clipboardError.value =
            'Não foi possível copiar automaticamente. Use o download em .docx como alternativa.';
    }
};

const resetWorkspace = () => {
    liveStatus.value = 'awaiting_confirmation';
    liveProgress.value = { stage: null, current: null, total: null };
    liveError.value = null;
    liveResult.value = null;
    startErrors.value = {};
    clipboardMessage.value = null;
    clipboardError.value = null;
    resetGoogleExport();
    uploadForm.reset();
    uploadForm.clearErrors();

    router.visit('/', {
        replace: true,
        preserveState: false,
    });
};

const formatBytes = (bytes: number) => {
    if (bytes >= 1024 * 1024 * 1024) {
        return `${(bytes / 1024 / 1024 / 1024).toFixed(1)} GB`;
    }

    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    return `${(bytes / 1024).toFixed(1)} KB`;
};

const formatDuration = (seconds: number) => {
    const hours = Math.floor(seconds / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    const remainingSeconds = Math.floor(seconds % 60);

    return [hours, minutes, remainingSeconds]
        .map((part) => part.toString().padStart(2, '0'))
        .join(':');
};

const formatApproximateTime = (seconds: number) => {
    if (seconds < 60) {
        return `cerca de ${Math.max(seconds, 1)} segundos`;
    }

    return `cerca de ${Math.ceil(seconds / 60)} minutos`;
};

const formatQuoteDate = (value: string) =>
    new Intl.DateTimeFormat('pt-BR', {
        dateStyle: 'short',
        timeStyle: 'medium',
    }).format(new Date(value));

const formatEstimatedValue = (value: number) =>
    new Intl.NumberFormat('pt-BR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value);

const pricingDescription = computed(() => {
    const pricing = selectedModel.value?.pricing;

    if (!pricing) {
        return '';
    }

    const unit = pricing.type === 'per_hour' ? 'hora' : 'minuto';

    return `US$ ${pricing.usd.toFixed(pricing.type === 'per_hour' ? 2 : 4)} por ${unit}`;
});

const activeExchangeRate = computed(() => props.exchange_rate ?? props.exchange_rate_fallback);

const localAvailability = (providerId: string, modelId: string): ModelAvailability => {
    if (!uploadForm.media || localDuration.value === null) {
        return {
            available: true,
            reason: null,
            requires_transcode: false,
            bitrate_kbps: null,
        };
    }

    return calculateAvailability(
        props.providers,
        providerId,
        modelId,
        localDuration.value,
        uploadForm.media.size,
    );
};

const availabilityFor = (providerId: string, modelId: string): ModelAvailability =>
    props.transcription?.model_availability[providerId]?.[modelId] ??
    localAvailability(providerId, modelId);

const providerHasAvailableModels = (providerId: string) =>
    Object.keys(props.providers[providerId]?.models ?? {}).some(
        (modelId) => availabilityFor(providerId, modelId).available,
    );

const selectedAvailability = computed(() =>
    availabilityFor(selectedProviderId.value, selectedModelId.value),
);

const localEstimate = computed<TranscriptionEstimate | null>(() => {
    if (localDuration.value === null || !selectedModel.value || !selectedProvider.value) {
        return null;
    }

    const costUsd = estimateCostUsd(selectedModel.value.pricing, localDuration.value);

    return {
        cost_usd: costUsd,
        cost_brl: Number((costUsd * activeExchangeRate.value.rate).toFixed(6)),
        exchange_rate: activeExchangeRate.value.rate,
        exchange_rate_quoted_at: activeExchangeRate.value.quoted_at,
        exchange_rate_source: activeExchangeRate.value.source,
        approximate_processing_seconds: Math.ceil((localDuration.value / 60) * 5 * 1.2),
        pricing_notice: selectedProvider.value.pricing_notice,
    };
});

const localPreviewComparison = computed<PreviewComparison | null>(() => {
    if (!localEstimate.value || localDuration.value === null) return null;

    return {
        duration_seconds: localDuration.value,
        cost_usd: localEstimate.value.cost_usd,
        availability: selectedAvailability.value,
        diarization_valid:
            !diarization.value || Boolean(selectedModel.value?.capabilities.diarization),
    };
});

const localRecommendedProviderId = computed(() => {
    if (localDuration.value === null || !uploadForm.media) return 'elevenlabs';

    const mini = localAvailability('openai', 'gpt-4o-mini-transcribe');
    return mini.available && !mini.requires_transcode ? 'openai' : 'elevenlabs';
});

const recommendedProvider = computed(() =>
    props.transcription
        ? props.providers[props.transcription.recommended_provider]
        : props.providers[localRecommendedProviderId.value],
);

const recommendedProviderId = computed(
    () => props.transcription?.recommended_provider ?? localRecommendedProviderId.value,
);

const openAiDiarizationUnavailable = computed(
    () =>
        selectedProviderId.value === 'openai' &&
        !availabilityFor('openai', 'gpt-4o-transcribe-diarize').available,
);

const estimateNeedsRefresh = computed(() => {
    const transcription = props.transcription;

    return (
        transcription !== null &&
        liveStatus.value === 'awaiting_confirmation' &&
        (transcription.provider !== selectedProviderId.value ||
            transcription.model !== selectedModelId.value ||
            transcription.diarization !== diarization.value)
    );
});

const actionLabel = computed(() => {
    if (uploadForm.processing) return 'Enviando e validando…';
    if (localDurationState.value === 'loading') return 'Lendo duração…';
    if (localDurationState.value === 'unavailable') return 'Enviar e calcular no servidor';
    return 'Iniciar transcrição';
});

const localFileTooLarge = computed(
    () => (uploadForm.media?.size ?? 0) > props.limits.max_upload_bytes,
);

const progressLabel = computed(() => {
    if (liveStatus.value === 'awaiting_confirmation') {
        return 'Aguardando confirmação';
    }

    if (liveStatus.value === 'queued') {
        return 'Na fila';
    }

    if (liveStatus.value === 'completed') {
        return 'Concluído';
    }

    if (liveStatus.value === 'failed') {
        return 'Falhou';
    }

    if (liveStatus.value === 'expired') {
        return 'Expirado';
    }

    if (liveProgress.value.stage === 'preparing') {
        return 'Preparando mídia';
    }

    if (liveProgress.value.stage === 'transcribing') {
        if (hasDeterminateProgress.value) {
            return `Chunk ${liveProgress.value.current} de ${liveProgress.value.total}`;
        }

        return 'Transcrevendo no provider';
    }

    if (liveProgress.value.stage === 'finalizing') {
        return 'Finalizando';
    }

    return 'Processando';
});

const hasDeterminateProgress = computed(
    () =>
        liveProgress.value.stage === 'transcribing' &&
        liveProgress.value.current !== null &&
        liveProgress.value.total !== null &&
        liveProgress.value.total > 0,
);

watch(selectedProviderId, () => {
    if (
        !selectedProvider.value?.models[selectedModelId.value] ||
        !availabilityFor(selectedProviderId.value, selectedModelId.value).available
    ) {
        selectedModelId.value =
            selectedModelIds.value.find(
                (modelId) => availabilityFor(selectedProviderId.value, modelId).available,
            ) ?? '';
        diarization.value = false;
    }

    loadApiKey();
});

watch(selectedModel, (model) => {
    if (!model?.capabilities.diarization) {
        diarization.value = false;
    }
});

watch(
    () => props.transcription,
    (transcription) => {
        if (!transcription) {
            return;
        }

        selectedProviderId.value = transcription.provider;
        selectedModelId.value = transcription.model;
        diarization.value = transcription.diarization;
        liveStatus.value = transcription.status;
        liveProgress.value = transcription.progress;
        liveError.value = transcription.error_message;
        liveResult.value = transcription.result;
        startPolling(transcription.id, transcription.status);
    },
    { immediate: true },
);

onMounted(() => {
    loadApiKey();
    void initializeGoogle();
});
</script>

<template>
    <Head title="Nova transcrição" />

    <main class="min-h-screen bg-slate-950 px-5 py-8 text-slate-100 sm:px-8">
        <div class="mx-auto max-w-5xl">
            <header class="mb-8 flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
                <div>
                    <p
                        class="mb-2 text-sm font-semibold tracking-[0.2em] text-emerald-400 uppercase"
                    >
                        Transcritor Web
                    </p>
                    <h1 class="text-3xl font-semibold tracking-tight sm:text-4xl">
                        Nova transcrição
                    </h1>
                </div>
                <span
                    class="w-fit rounded-full border border-emerald-400/20 bg-emerald-400/10 px-3 py-1 text-xs font-medium text-emerald-300"
                >
                    Sessão desbloqueada
                </span>
            </header>

            <div class="grid gap-6 lg:grid-cols-[1.35fr_0.65fr]">
                <section class="space-y-6 rounded-3xl border border-white/10 bg-white/5 p-6 sm:p-8">
                    <div class="grid gap-5 sm:grid-cols-2">
                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-200"
                                >Provider</span
                            >
                            <select
                                v-model="selectedProviderId"
                                class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-400/10"
                            >
                                <option
                                    v-for="providerId in providerIds"
                                    :key="providerId"
                                    :value="providerId"
                                    :disabled="!providerHasAvailableModels(providerId)"
                                >
                                    {{ providers[providerId].label }}
                                    {{
                                        providerHasAvailableModels(providerId)
                                            ? recommendedProviderId === providerId
                                                ? '— recomendado'
                                                : ''
                                            : '— indisponível'
                                    }}
                                </option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="mb-2 block text-sm font-medium text-slate-200"
                                >Modelo</span
                            >
                            <select
                                v-model="selectedModelId"
                                class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-400/10"
                            >
                                <option
                                    v-for="modelId in selectedModelIds"
                                    :key="modelId"
                                    :value="modelId"
                                    :disabled="
                                        !availabilityFor(selectedProviderId, modelId).available
                                    "
                                >
                                    {{ selectedProvider?.models[modelId].label }}
                                    {{
                                        availabilityFor(selectedProviderId, modelId).available
                                            ? ''
                                            : '— indisponível'
                                    }}
                                </option>
                            </select>
                            <span
                                v-if="!selectedAvailability.available"
                                class="mt-2 block text-xs leading-5 text-amber-300"
                            >
                                {{ selectedAvailability.reason }}
                            </span>
                        </label>
                    </div>

                    <p
                        v-if="(transcription || localEstimate) && recommendedProvider"
                        class="rounded-2xl border border-emerald-400/20 bg-emerald-400/10 p-4 text-sm leading-6 text-emerald-100"
                    >
                        Recomendado para este arquivo:
                        <strong>{{ recommendedProvider.label }}</strong>
                        <template v-if="recommendedProviderId === 'elevenlabs'">
                            — recebe a mídia original, preserva a qualidade e oferece diarização.
                        </template>
                        <template v-else>
                            — o modelo Mini é a opção de menor custo e não exige conversão.
                        </template>
                    </p>

                    <label class="block">
                        <span class="mb-2 block text-sm font-medium text-slate-200">
                            API key da {{ selectedProvider?.label }}
                        </span>
                        <input
                            :value="apiKey"
                            type="password"
                            autocomplete="off"
                            spellcheck="false"
                            class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 font-mono text-sm outline-none focus:border-emerald-400 focus:ring-4 focus:ring-emerald-400/10"
                            placeholder="Cole sua chave aqui"
                            @input="updateApiKey"
                        />
                    </label>

                    <form class="space-y-4" @submit.prevent="submitUpload">
                        <label
                            class="block cursor-pointer rounded-2xl border border-dashed border-white/15 bg-slate-900/60 p-6 transition hover:border-emerald-400/50"
                        >
                            <span class="block font-medium">Escolher áudio ou vídeo</span>
                            <span class="mt-1 block text-sm text-slate-400">
                                MP3, MP4, MPEG, MPGA, M4A, WAV ou WEBM — até
                                {{ formatBytes(limits.max_upload_bytes) }} e 4 horas
                            </span>
                            <input
                                type="file"
                                class="mt-4 block w-full text-sm text-slate-300 file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-400 file:px-4 file:py-2 file:font-semibold file:text-slate-950"
                                accept=".mp3,.mp4,.mpeg,.mpga,.m4a,.wav,.webm"
                                @change="selectFile"
                            />
                            <span
                                v-if="uploadForm.media"
                                class="mt-3 block text-sm text-emerald-300"
                            >
                                {{ uploadForm.media.name }} ·
                                {{ formatBytes(uploadForm.media.size) }}
                            </span>
                            <span
                                v-if="localDurationState === 'loading'"
                                class="mt-2 block text-xs text-slate-400"
                            >
                                Lendo a duração localmente, sem enviar o arquivo…
                            </span>
                            <span
                                v-if="localDurationState === 'ready' && localDuration !== null"
                                class="mt-2 block text-xs text-slate-300"
                            >
                                Duração local: {{ formatDuration(localDuration) }}
                            </span>
                            <span
                                v-if="localDurationError"
                                class="mt-2 block text-xs leading-5 text-amber-300"
                            >
                                {{ localDurationError }}
                            </span>
                        </label>

                        <p v-if="uploadForm.errors.media" class="text-sm text-rose-400">
                            {{ uploadForm.errors.media }}
                        </p>
                        <p v-if="startErrors.api_key" class="text-sm text-rose-400">
                            {{ startErrors.api_key }}
                        </p>
                        <p
                            v-if="
                                uploadForm.errors.provider ||
                                uploadForm.errors.model ||
                                uploadForm.errors.diarization
                            "
                            class="text-sm text-rose-400"
                        >
                            {{
                                uploadForm.errors.provider ||
                                uploadForm.errors.model ||
                                uploadForm.errors.diarization
                            }}
                        </p>

                        <label
                            class="flex items-start gap-3 rounded-2xl border border-white/10 p-4"
                        >
                            <input
                                v-model="diarization"
                                type="checkbox"
                                :disabled="!selectedModel?.capabilities.diarization"
                                class="mt-1 size-4 accent-emerald-400 disabled:opacity-40"
                            />
                            <span>
                                <span class="block text-sm font-medium">Identificar falantes</span>
                                <span class="mt-1 block text-xs leading-5 text-slate-400">
                                    {{
                                        selectedModel?.capabilities.diarization
                                            ? 'Disponível para este modelo.'
                                            : 'Este modelo não oferece diarização.'
                                    }}
                                </span>
                                <span
                                    v-if="openAiDiarizationUnavailable"
                                    class="mt-2 block text-xs leading-5 text-amber-300"
                                >
                                    Acima de 25 minutos, a diarização fica disponível somente na
                                    ElevenLabs.
                                </span>
                            </span>
                        </label>

                        <div v-if="uploadForm.progress" class="space-y-2">
                            <progress
                                class="h-2 w-full accent-emerald-400"
                                :value="uploadForm.progress.percentage ?? 0"
                                max="100"
                            />
                            <p class="text-xs text-slate-400">
                                Enviando: {{ uploadForm.progress.percentage ?? 0 }}%
                            </p>
                        </div>

                        <button
                            type="submit"
                            :disabled="
                                uploadForm.processing ||
                                !uploadForm.media ||
                                localFileTooLarge ||
                                localDurationState === 'loading' ||
                                (localDuration !== null &&
                                    localDuration > limits.max_duration_seconds) ||
                                !selectedAvailability.available
                            "
                            class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
                        >
                            {{ actionLabel }}
                        </button>
                    </form>
                </section>

                <aside class="h-fit space-y-5 rounded-3xl border border-white/10 bg-white/5 p-6">
                    <template v-if="transcription">
                        <div>
                            <p
                                class="text-xs font-semibold tracking-wider text-slate-500 uppercase"
                            >
                                Arquivo validado
                            </p>
                            <p class="mt-2 font-medium break-words text-slate-100">
                                {{ transcription.original_filename }}
                            </p>
                            <p class="mt-1 text-sm text-slate-400">
                                {{ formatBytes(transcription.size_bytes) }} ·
                                {{ formatDuration(transcription.duration_seconds) }}
                            </p>
                        </div>

                        <template
                            v-if="liveStatus === 'awaiting_confirmation' && transcription.estimate"
                        >
                            <div
                                v-if="confirmationMessage"
                                class="rounded-xl border border-amber-400/20 bg-amber-400/10 p-3 text-sm leading-6 text-amber-100"
                            >
                                {{ confirmationMessage }} Confira os valores e confirme para
                                iniciar.
                            </div>

                            <div
                                v-if="confirmationMessage && previewAtUpload"
                                class="grid grid-cols-2 gap-3 border-t border-white/10 pt-5"
                            >
                                <div class="rounded-xl bg-slate-900/70 p-3">
                                    <p class="text-xs text-slate-500">Prévia no navegador</p>
                                    <p class="mt-1 font-semibold text-slate-200">
                                        {{ formatDuration(previewAtUpload.duration_seconds) }}
                                    </p>
                                    <p class="mt-1 text-sm text-slate-300">
                                        US$ {{ formatEstimatedValue(previewAtUpload.cost_usd) }}
                                    </p>
                                </div>
                                <div
                                    class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-3"
                                >
                                    <p class="text-xs text-emerald-200">Confirmado pelo servidor</p>
                                    <p class="mt-1 font-semibold text-emerald-100">
                                        {{ formatDuration(transcription.duration_seconds) }}
                                    </p>
                                    <p class="mt-1 text-sm text-emerald-200">
                                        US$
                                        {{ formatEstimatedValue(transcription.estimate.cost_usd) }}
                                    </p>
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-3 border-t border-white/10 pt-5">
                                <div class="rounded-xl bg-slate-900/70 p-3">
                                    <p class="text-xs text-slate-500">Estimativa USD</p>
                                    <p class="mt-1 text-lg font-semibold text-emerald-300">
                                        US$
                                        {{ formatEstimatedValue(transcription.estimate.cost_usd) }}
                                    </p>
                                </div>
                                <div class="rounded-xl bg-slate-900/70 p-3">
                                    <p class="text-xs text-slate-500">Estimativa BRL</p>
                                    <p class="mt-1 text-lg font-semibold text-emerald-300">
                                        R$
                                        {{ formatEstimatedValue(transcription.estimate.cost_brl) }}
                                    </p>
                                </div>
                            </div>

                            <div
                                class="space-y-2 border-t border-white/10 pt-5 text-sm text-slate-300"
                            >
                                <p>
                                    Cotação usada: R$
                                    {{ formatEstimatedValue(transcription.estimate.exchange_rate) }}
                                </p>
                                <p>
                                    {{
                                        formatQuoteDate(
                                            transcription.estimate.exchange_rate_quoted_at,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="
                                        transcription.estimate.exchange_rate_source === 'fallback'
                                    "
                                    class="text-amber-300"
                                >
                                    AwesomeAPI indisponível; usando cotação fixa de fallback.
                                </p>
                            </div>

                            <div class="border-t border-white/10 pt-5">
                                <p
                                    class="text-xs font-semibold tracking-wider text-slate-500 uppercase"
                                >
                                    Tempo aproximado
                                </p>
                                <p class="mt-2 text-sm text-slate-300">
                                    {{
                                        formatApproximateTime(
                                            transcription.estimate.approximate_processing_seconds,
                                        )
                                    }}
                                </p>
                            </div>

                            <p
                                class="border-t border-white/10 pt-5 text-xs leading-5 text-slate-400"
                            >
                                {{ transcription.estimate.pricing_notice }} Esta é apenas uma
                                estimativa pelo preço de tabela, não uma garantia do valor cobrado.
                            </p>

                            <p
                                v-if="selectedAvailability.requires_transcode"
                                class="rounded-xl border border-sky-400/20 bg-sky-400/10 p-3 text-xs leading-5 text-sky-200"
                            >
                                Antes de enviar à OpenAI, o áudio será convertido uma vez para Opus
                                mono a {{ selectedAvailability.bitrate_kbps }} kbps.
                            </p>

                            <p
                                v-if="!selectedAvailability.available"
                                class="rounded-xl border border-amber-400/20 bg-amber-400/10 p-3 text-xs leading-5 text-amber-200"
                            >
                                {{ selectedAvailability.reason }} Escolha outra combinação para
                                continuar.
                            </p>

                            <p
                                v-if="automaticStartPending"
                                class="rounded-xl border border-emerald-400/20 bg-emerald-400/10 p-3 text-sm text-emerald-100"
                            >
                                Arquivo validado e estimativa confirmada. Enfileirando a
                                transcrição…
                            </p>

                            <button
                                v-if="!automaticStartPending"
                                type="button"
                                :disabled="
                                    estimateForm.processing ||
                                    !estimateNeedsRefresh ||
                                    !selectedAvailability.available
                                "
                                class="w-full rounded-xl border border-emerald-400/40 px-4 py-3 text-sm font-semibold text-emerald-300 transition hover:bg-emerald-400/10 disabled:cursor-not-allowed disabled:border-white/10 disabled:text-slate-500"
                                @click="recalculateEstimate"
                            >
                                {{
                                    estimateForm.processing
                                        ? 'Recalculando…'
                                        : estimateNeedsRefresh
                                          ? 'Recalcular sem reenviar arquivo'
                                          : 'Estimativa atualizada'
                                }}
                            </button>

                            <p
                                v-if="!automaticStartPending && startErrors.api_key"
                                class="text-sm text-rose-400"
                            >
                                {{ startErrors.api_key }}
                            </p>
                            <p v-if="startErrors.transcription" class="text-sm text-rose-400">
                                {{ startErrors.transcription }}
                            </p>
                            <p v-if="estimateForm.errors.model" class="text-sm text-rose-400">
                                {{ estimateForm.errors.model }}
                            </p>

                            <button
                                v-if="!automaticStartPending"
                                type="button"
                                :disabled="
                                    startProcessing ||
                                    estimateForm.processing ||
                                    estimateNeedsRefresh ||
                                    !selectedAvailability.available
                                "
                                class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950 transition hover:bg-emerald-300 disabled:cursor-not-allowed disabled:bg-slate-700 disabled:text-slate-400"
                                @click="startTranscription()"
                            >
                                {{
                                    startProcessing
                                        ? 'Enfileirando…'
                                        : confirmationMessage
                                          ? 'Confirmar valor corrigido e iniciar'
                                          : 'Iniciar transcrição'
                                }}
                            </button>
                        </template>

                        <div v-else class="space-y-4 border-t border-white/10 pt-5">
                            <div class="flex items-center justify-between gap-3">
                                <p class="font-medium text-slate-100">{{ progressLabel }}</p>
                                <span v-if="isPolling" class="text-xs text-slate-500">
                                    Atualizando…
                                </span>
                            </div>

                            <progress
                                v-if="hasDeterminateProgress"
                                class="h-2 w-full accent-emerald-400"
                                :value="liveProgress.current ?? 0"
                                :max="liveProgress.total ?? 1"
                            />
                            <progress
                                v-else-if="liveStatus === 'queued' || liveStatus === 'processing'"
                                class="h-2 w-full accent-emerald-400"
                            />

                            <p v-if="liveError" class="text-sm text-rose-400">
                                {{ liveError }}
                            </p>

                            <p v-if="liveStatus === 'completed'" class="text-sm text-emerald-300">
                                A transcrição terminou e a mídia temporária foi removida.
                            </p>
                            <div
                                v-if="liveStatus === 'failed'"
                                class="space-y-3 rounded-xl border border-rose-400/20 bg-rose-400/10 p-4"
                            >
                                <p class="text-sm leading-6 text-rose-100">
                                    A mídia temporária foi removida. Para tentar novamente, envie o
                                    arquivo em uma nova transcrição.
                                </p>
                                <button
                                    type="button"
                                    class="w-full rounded-xl bg-rose-300 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-rose-200"
                                    @click="resetWorkspace"
                                >
                                    Iniciar nova transcrição
                                </button>
                            </div>
                            <p v-if="liveStatus === 'expired'" class="text-sm text-amber-300">
                                A confirmação expirou e a mídia temporária foi removida.
                            </p>
                            <button
                                v-if="liveStatus === 'expired'"
                                type="button"
                                class="w-full rounded-xl border border-amber-300/40 px-4 py-3 text-sm font-semibold text-amber-200 transition hover:bg-amber-300/10"
                                @click="resetWorkspace"
                            >
                                Iniciar nova transcrição
                            </button>
                        </div>
                    </template>

                    <template v-else-if="localEstimate && uploadForm.media">
                        <div>
                            <p
                                class="text-xs font-semibold tracking-wider text-slate-500 uppercase"
                            >
                                Prévia local — nenhum upload feito
                            </p>
                            <p class="mt-2 font-medium break-words text-slate-100">
                                {{ uploadForm.media.name }}
                            </p>
                            <p class="mt-1 text-sm text-slate-400">
                                {{ formatBytes(uploadForm.media.size) }} ·
                                {{ formatDuration(localDuration ?? 0) }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-3 border-t border-white/10 pt-5">
                            <div class="rounded-xl bg-slate-900/70 p-3">
                                <p class="text-xs text-slate-500">Estimativa USD</p>
                                <p class="mt-1 text-lg font-semibold text-emerald-300">
                                    US$ {{ formatEstimatedValue(localEstimate.cost_usd) }}
                                </p>
                            </div>
                            <div class="rounded-xl bg-slate-900/70 p-3">
                                <p class="text-xs text-slate-500">Estimativa BRL</p>
                                <p class="mt-1 text-lg font-semibold text-emerald-300">
                                    R$ {{ formatEstimatedValue(localEstimate.cost_brl) }}
                                </p>
                            </div>
                        </div>

                        <div class="space-y-2 border-t border-white/10 pt-5 text-sm text-slate-300">
                            <p>
                                Cotação usada: R$
                                {{ formatEstimatedValue(localEstimate.exchange_rate) }}
                            </p>
                            <p>{{ formatQuoteDate(localEstimate.exchange_rate_quoted_at) }}</p>
                            <p v-if="!exchange_rate" class="text-amber-300">
                                Atualizando a cotação; a prévia usa o fallback por enquanto.
                            </p>
                            <p
                                v-else-if="localEstimate.exchange_rate_source === 'fallback'"
                                class="text-amber-300"
                            >
                                AwesomeAPI indisponível; usando cotação fixa de fallback.
                            </p>
                        </div>

                        <p
                            v-if="selectedAvailability.requires_transcode"
                            class="rounded-xl border border-sky-400/20 bg-sky-400/10 p-3 text-xs leading-5 text-sky-200"
                        >
                            A OpenAI exigirá uma conversão para Opus mono a
                            {{ selectedAvailability.bitrate_kbps }} kbps.
                        </p>
                        <p
                            v-if="!selectedAvailability.available"
                            class="rounded-xl border border-amber-400/20 bg-amber-400/10 p-3 text-xs leading-5 text-amber-200"
                        >
                            {{ selectedAvailability.reason }}
                        </p>
                        <p class="border-t border-white/10 pt-5 text-xs leading-5 text-slate-400">
                            {{ localEstimate.pricing_notice }} Esta é apenas uma estimativa pelo
                            preço de tabela, não uma garantia do valor cobrado. A duração será
                            validada pelo servidor antes de qualquer chamada ao provider.
                        </p>
                    </template>

                    <template v-else>
                        <div>
                            <p
                                class="text-xs font-semibold tracking-wider text-slate-500 uppercase"
                            >
                                Preço configurado
                            </p>
                            <p class="mt-2 text-xl font-semibold text-emerald-300">
                                {{ pricingDescription }}
                            </p>
                        </div>

                        <div class="border-t border-white/10 pt-5">
                            <p
                                class="text-xs font-semibold tracking-wider text-slate-500 uppercase"
                            >
                                Limite do provider
                            </p>
                            <p class="mt-2 text-sm text-slate-300">
                                {{
                                    formatBytes(
                                        selectedProvider?.constraints.max_file_size_bytes ?? 0,
                                    )
                                }}
                                por arquivo
                            </p>
                        </div>

                        <p class="border-t border-white/10 pt-5 text-xs leading-5 text-slate-400">
                            {{ selectedProvider?.pricing_notice }}
                        </p>
                    </template>
                </aside>
            </div>

            <section
                v-if="liveStatus === 'completed' && liveResult"
                class="mt-6 rounded-3xl border border-white/10 bg-white/5 p-6 sm:p-8"
            >
                <div class="mb-5 flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-xl font-semibold">Transcrição</h2>
                        <span v-if="liveResult.language" class="mt-1 block text-xs text-slate-400">
                            Idioma: {{ liveResult.language }}
                        </span>
                    </div>
                    <button
                        type="button"
                        :disabled="isCreatingGoogleDocument"
                        class="rounded-xl border border-white/15 px-4 py-2 text-sm font-medium text-slate-200 transition hover:bg-white/10 disabled:cursor-not-allowed disabled:text-slate-500"
                        @click="resetWorkspace"
                    >
                        Nova transcrição
                    </button>
                </div>

                <div class="mb-6 grid gap-3 sm:grid-cols-3">
                    <button
                        type="button"
                        class="rounded-xl bg-emerald-400 px-4 py-3 text-sm font-semibold text-slate-950 transition hover:bg-emerald-300"
                        @click="copyTranscription"
                    >
                        Copiar texto
                    </button>
                    <a
                        v-if="transcription"
                        :href="`/transcriptions/${transcription.id}/export.docx`"
                        class="rounded-xl border border-emerald-400/40 px-4 py-3 text-center text-sm font-semibold text-emerald-300 transition hover:bg-emerald-400/10"
                    >
                        Baixar .docx
                    </a>
                    <button
                        type="button"
                        :disabled="!isGoogleReady || isCreatingGoogleDocument || !transcription"
                        class="rounded-xl border border-sky-400/40 px-4 py-3 text-sm font-semibold text-sky-300 transition hover:bg-sky-400/10 disabled:cursor-not-allowed disabled:border-white/10 disabled:text-slate-500"
                        @click="
                            transcription &&
                            createGoogleDocument(transcription.id, transcription.original_filename)
                        "
                    >
                        {{
                            isCreatingGoogleDocument ? 'Criando no Google…' : 'Criar no Google Docs'
                        }}
                    </button>
                </div>

                <div class="mb-6 space-y-2 text-sm" aria-live="polite">
                    <p v-if="clipboardMessage" class="text-emerald-300">
                        {{ clipboardMessage }}
                    </p>
                    <p v-if="clipboardError" class="text-rose-300">
                        {{ clipboardError }}
                    </p>
                    <p v-if="googleSuccessMessage" class="text-emerald-300">
                        {{ googleSuccessMessage }}
                    </p>
                    <p v-if="googleError" class="text-rose-300">
                        {{ googleError }} Copiar e baixar em .docx continuam disponíveis.
                    </p>
                    <a
                        v-if="googleDocumentUrl"
                        :href="googleDocumentUrl"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-block font-medium text-sky-300 underline underline-offset-4"
                    >
                        Abrir documento criado
                    </a>
                </div>

                <p class="text-sm leading-7 whitespace-pre-wrap text-slate-200">
                    {{ liveResult.export_text }}
                </p>
            </section>
        </div>
    </main>
</template>
