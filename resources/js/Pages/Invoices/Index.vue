<script setup>
import { onMounted, ref, watch } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import StatusBadge from '../../Components/StatusBadge.vue';
import { handleApiError } from '../../composables/api';

const rows = ref([]);
const meta = ref({});
const loading = ref(true);
const status = ref(new URLSearchParams(window.location.search).get('status') ?? '');
const page = ref(1);

const load = async () => {
    loading.value = true;
    try {
        const { data } = await axios.get('/api/invoices', {
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
    <Head title="Factures" />
    <h1 class="mb-4 text-lg font-semibold text-slate-900">Factures</h1>

    <div class="mb-3">
        <select v-model="status" class="rounded-md border border-slate-300 px-2 py-1 text-sm">
            <option value="">Tous les statuts</option>
            <option value="submitted">Soumise</option>
            <option value="partially_matched">Partiel</option>
            <option value="matched">Rapprochée</option>
            <option value="needs_review">À revoir</option>
        </select>
    </div>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table class="w-full text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase text-slate-500">
                <tr><th class="px-4 py-2">Référence</th><th class="px-4 py-2">Bon de commande</th><th class="px-4 py-2">Date</th><th class="px-4 py-2">Lignes</th><th class="px-4 py-2">Statut</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                <tr v-if="loading"><td colspan="5" class="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
                <tr v-else-if="!rows.length"><td colspan="5" class="px-4 py-6 text-center text-slate-400">Aucune facture.</td></tr>
                <tr v-for="i in rows" :key="i.id" class="hover:bg-slate-50">
                    <td class="px-4 py-2"><Link :href="`/invoices/${i.id}`" class="font-medium text-slate-900 hover:underline">{{ i.reference }}</Link></td>
                    <td class="px-4 py-2 text-slate-600">{{ i.purchase_order?.reference }}</td>
                    <td class="px-4 py-2 text-slate-600">{{ i.invoice_date }}</td>
                    <td class="px-4 py-2 text-slate-600">{{ i.lines_count }}</td>
                    <td class="px-4 py-2"><StatusBadge :status="i.status" /></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div v-if="meta.last_page > 1" class="mt-3 flex items-center gap-2 text-sm">
        <button :disabled="page <= 1" class="rounded border px-2 py-1 disabled:opacity-40" @click="page--; load()">Précédent</button>
        <span class="text-slate-500">Page {{ meta.current_page }} / {{ meta.last_page }}</span>
        <button :disabled="page >= meta.last_page" class="rounded border px-2 py-1 disabled:opacity-40" @click="page++; load()">Suivant</button>
    </div>
</template>
