<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import { handleApiError } from '../composables/api';

const loading = ref(true);
const stats = ref({ pos: 0, invoices: 0, needsReview: 0, byStatus: {} });

const total = async (url) => (await axios.get(url)).data.meta.total;

onMounted(async () => {
    try {
        const [pos, invoices, review, matched, partial, submitted] = await Promise.all([
            total('/api/purchase-orders?per_page=1'),
            total('/api/invoices?per_page=1'),
            axios.get('/api/match-decisions?status=needs_review').then((r) => r.data.data.length),
            total('/api/invoices?per_page=1&status=matched'),
            total('/api/invoices?per_page=1&status=partially_matched'),
            total('/api/invoices?per_page=1&status=submitted'),
        ]);
        stats.value = {
            pos,
            invoices,
            needsReview: review,
            byStatus: { matched, partially_matched: partial, submitted, needs_review: review },
        };
    } catch (e) {
        handleApiError(e);
    } finally {
        loading.value = false;
    }
});

// Répartition des factures par statut → barre segmentée + légende.
const SEG = [
    { key: 'matched', label: 'Rapprochées', bar: 'bg-emerald-500', dot: 'bg-emerald-500' },
    { key: 'partially_matched', label: 'Partielles', bar: 'bg-amber-500', dot: 'bg-amber-500' },
    { key: 'submitted', label: 'Soumises', bar: 'bg-slate-300', dot: 'bg-slate-300' },
    { key: 'needs_review', label: 'À revoir', bar: 'bg-rose-500', dot: 'bg-rose-500' },
];
const segTotal = computed(() => SEG.reduce((s, x) => s + (stats.value.byStatus[x.key] || 0), 0));
const segments = computed(() =>
    SEG.map((x) => {
        const value = stats.value.byStatus[x.key] || 0;
        return { ...x, value, pct: segTotal.value ? (value / segTotal.value) * 100 : 0 };
    }),
);
</script>

<template>
    <Head title="Tableau de bord" />

    <div class="mb-6">
        <h1 class="text-xl font-semibold text-slate-900">Tableau de bord</h1>
        <p class="mt-0.5 text-sm text-slate-500">Vue d'ensemble du rapprochement fournisseurs.</p>
    </div>

    <!-- Squelettes -->
    <div v-if="loading" class="space-y-4">
        <div class="h-28 animate-pulse rounded-2xl bg-slate-100" />
        <div class="grid grid-cols-3 gap-4">
            <div v-for="i in 3" :key="i" class="h-24 animate-pulse rounded-xl bg-slate-100" />
        </div>
    </div>

    <div v-else class="space-y-6">
        <!-- Héros : la file de risque (écarts à revoir) -->
        <Link
            href="/review"
            class="group flex items-center justify-between gap-4 rounded-2xl border p-5 transition sm:p-6"
            :class="stats.needsReview
                ? 'border-rose-200 bg-rose-50 hover:border-rose-300'
                : 'border-emerald-200 bg-emerald-50 hover:border-emerald-300'"
        >
            <div>
                <div class="flex items-center gap-2 text-sm font-medium" :class="stats.needsReview ? 'text-rose-700' : 'text-emerald-700'">
                    <span class="h-2 w-2 rounded-full" :class="stats.needsReview ? 'bg-rose-500' : 'bg-emerald-500'" />
                    Écarts à revoir
                </div>
                <div class="mt-2 flex items-baseline gap-3">
                    <span class="tnum text-4xl font-semibold tracking-tight" :class="stats.needsReview ? 'text-rose-700' : 'text-emerald-700'">
                        {{ stats.needsReview }}
                    </span>
                    <span class="text-sm" :class="stats.needsReview ? 'text-rose-600/80' : 'text-emerald-600/80'">
                        {{ stats.needsReview ? 'décisions en attente de validation humaine' : 'aucun écart en attente' }}
                    </span>
                </div>
            </div>
            <span
                class="hidden shrink-0 items-center gap-1 rounded-lg bg-white/70 px-3 py-1.5 text-sm font-medium ring-1 ring-inset sm:inline-flex"
                :class="stats.needsReview ? 'text-rose-700 ring-rose-200' : 'text-emerald-700 ring-emerald-200'"
            >
                Ouvrir la revue
                <svg class="h-4 w-4 transition group-hover:translate-x-0.5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" d="M7.3 4.3a1 1 0 011.4 0l5 5a1 1 0 010 1.4l-5 5a1 1 0 11-1.4-1.4L11.6 10 7.3 5.7a1 1 0 010-1.4z" clip-rule="evenodd" />
                </svg>
            </span>
        </Link>

        <!-- Tuiles KPI (plus discrètes que le héros) -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <Link href="/purchase-orders" class="card p-4 transition hover:border-slate-300">
                <div class="tnum text-2xl font-semibold text-slate-900">{{ stats.pos }}</div>
                <div class="mt-0.5 text-sm text-slate-500">Bons de commande</div>
            </Link>
            <Link href="/invoices" class="card p-4 transition hover:border-slate-300">
                <div class="tnum text-2xl font-semibold text-slate-900">{{ stats.invoices }}</div>
                <div class="mt-0.5 text-sm text-slate-500">Factures</div>
            </Link>
            <Link href="/invoices?status=matched" class="card p-4 transition hover:border-slate-300">
                <div class="tnum text-2xl font-semibold text-emerald-600">{{ stats.byStatus.matched }}</div>
                <div class="mt-0.5 text-sm text-slate-500">Factures rapprochées</div>
            </Link>
        </div>

        <!-- Répartition des factures par statut -->
        <div class="card p-5">
            <h2 class="text-sm font-semibold text-slate-900">Répartition des factures</h2>
            <div v-if="segTotal" class="mt-4">
                <div class="flex h-2.5 overflow-hidden rounded-full bg-slate-100">
                    <div v-for="s in segments" :key="s.key" :class="s.bar" :style="{ width: s.pct + '%' }" />
                </div>
                <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <div v-for="s in segments" :key="s.key" class="flex items-center gap-2">
                        <span class="h-2.5 w-2.5 rounded-full" :class="s.dot" />
                        <span class="text-sm text-slate-600">{{ s.label }}</span>
                        <span class="tnum ml-auto text-sm font-medium text-slate-900">{{ s.value }}</span>
                    </div>
                </div>
            </div>
            <p v-else class="mt-3 text-sm text-slate-400">Aucune facture pour le moment.</p>
        </div>
    </div>
</template>
