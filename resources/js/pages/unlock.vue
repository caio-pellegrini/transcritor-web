<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({
    password: '',
});

const submit = () => {
    form.post('/unlock', {
        preserveScroll: true,
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Desbloquear" />

    <main
        class="flex min-h-screen items-center justify-center bg-slate-950 px-6 py-12 text-slate-100"
    >
        <section
            class="w-full max-w-md rounded-3xl border border-white/10 bg-white/5 p-8 shadow-2xl shadow-black/30 backdrop-blur"
        >
            <div class="mb-8">
                <p class="mb-2 text-sm font-semibold tracking-[0.2em] text-emerald-400 uppercase">
                    Transcritor Web
                </p>
                <h1 class="text-3xl font-semibold tracking-tight">Acesso protegido</h1>
                <p class="mt-3 leading-6 text-slate-400">
                    Digite a senha familiar para abrir o transcritor.
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label for="password" class="mb-2 block text-sm font-medium text-slate-200">
                        Senha
                    </label>
                    <input
                        id="password"
                        v-model="form.password"
                        name="password"
                        type="password"
                        autocomplete="current-password"
                        autofocus
                        class="w-full rounded-xl border border-white/10 bg-slate-900 px-4 py-3 text-slate-100 transition outline-none placeholder:text-slate-600 focus:border-emerald-400 focus:ring-4 focus:ring-emerald-400/10"
                        placeholder="Digite a senha de acesso"
                    />
                    <p v-if="form.errors.password" class="mt-2 text-sm text-rose-400">
                        {{ form.errors.password }}
                    </p>
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-xl bg-emerald-400 px-4 py-3 font-semibold text-slate-950 transition hover:bg-emerald-300 disabled:cursor-wait disabled:opacity-60"
                >
                    {{ form.processing ? 'Verificando…' : 'Desbloquear' }}
                </button>
            </form>
        </section>
    </main>
</template>
