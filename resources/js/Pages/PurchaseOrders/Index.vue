<script setup>
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import StatusBadge from '../../Components/StatusBadge.vue';
import { handleApiError } from '../../composables/api';

const rows = ref([]);
const meta = ref({});
const loading = ref(true);
const status = ref('');
const page = ref(1);

const filters = [
    { value: '', label: 'Tous' },
    { value: 'open', label: 'Ouverts' },
    { value: 'closed', label: 'Clos' },
    { value: 'cancelled', label: 'Annulés' },
];

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/purchase-orders', {
            params: { status: status.value || undefined, page: page.value },
        });
        rows.value = data.data;
        meta.value = data.meta;
    } catch (e) {
        handleApiError(e);
    } finally {
        loading.value = false;
    }
};

watch(status, () => {
    page.value = 1;
    load();
});
onMounted(load);
</script>

<template>
    <Head title="Bons de commande" />

    <div class="mb-6 flex items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-semibold text-slate-900">Bons de commande</h1>
            <p class="mt-0.5 text-sm text-slate-500">Commandes fournisseurs et leur état de rapprochement.</p>
        </div>
        <Link href="/purchase-orders/create" class="btn-primary">
            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path d="M10 4a1 1 0 011 1v4h4a1 1 0 110 2h-4v4a1 1 0 11-2 0v-4H5a1 1 0 110-2h4V5a1 1 0 011-1z" /></svg>
            Nouveau
        </Link>
    </div>

    <!-- Filtres -->
    <div class="mb-4 inline-flex rounded-lg border border-slate-200 bg-white p-0.5 text-sm">
        <button
            v-for="f in filters"
            :key="f.value"
            class="rounded-md px-3 py-1.5 font-medium transition"
            :class="status === f.value ? 'bg-ink-900 text-white' : 'text-slate-600 hover:bg-slate-100'"
            @click="status = f.value"
        >
            {{ f.label }}
        </button>
    </div>

    <div class="card overflow-hidden">
        <table class="w-full">
            <thead class="border-b border-slate-200 bg-slate-50/60">
                <tr>
                    <th class="th">Référence</th>
                    <th class="th">Fournisseur</th>
                    <th class="th">Projet</th>
                    <th class="th text-right">Lignes</th>
                    <th class="th">Statut</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <!-- Squelettes -->
                <template v-if="loading">
                    <tr v-for="i in 5" :key="`s${i}`">
                        <td v-for="c in 5" :key="c" class="px-4 py-3"><div class="h-3.5 animate-pulse rounded bg-slate-100" /></td>
                    </tr>
                </template>

                <!-- État vide -->
                <tr v-else-if="!rows.length">
                    <td colspan="5" class="px-4 py-12 text-center">
                        <p class="text-sm font-medium text-slate-700">Aucun bon de commande</p>
                        <p class="mt-1 text-sm text-slate-500">Créez-en un pour lancer le suivi des livraisons et factures.</p>
                        <Link href="/purchase-orders/create" class="btn-ghost btn-sm mt-4">Créer un bon de commande</Link>
                    </td>
                </tr>

                <!-- Données -->
                <template v-else>
                <tr v-for="po in rows" :key="po.id" class="transition hover:bg-slate-50">
                    <td class="px-4 py-3">
                        <Link :href="`/purchase-orders/${po.id}`" class="font-medium text-slate-900 hover:text-brand-700">{{ po.reference }}</Link>
                    </td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ po.supplier?.name }}</td>
                    <td class="px-4 py-3 text-sm text-slate-600">{{ po.project?.name }}</td>
                    <td class="px-4 py-3 text-right">
                        <span class="tnum inline-flex min-w-6 justify-center rounded-md bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-600">{{ po.lines_count }}</span>
                    </td>
                    <td class="px-4 py-3"><StatusBadge :status="po.status" /></td>
                </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div v-if="meta.last_page > 1" class="mt-4 flex items-center justify-between text-sm">
        <span class="text-slate-500">Page <span class="tnum">{{ meta.current_page }}</span> sur <span class="tnum">{{ meta.last_page }}</span></span>
        <div class="flex gap-2">
            <button :disabled="page <= 1" class="btn-ghost btn-sm" @click="page--; load()">Précédent</button>
            <button :disabled="page >= meta.last_page" class="btn-ghost btn-sm" @click="page++; load()">Suivant</button>
        </div>
    </div>
</template>
