<script setup>
import { computed, onMounted, ref } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Reasons from '../../Components/Reasons.vue';
import { handleApiError } from '../../composables/api';
import { pushToast } from '../../composables/toast';

const page = usePage();
const isReviewer = computed(() => page.props.auth?.user?.is_reviewer);

const rows = ref([]);
const loading = ref(true);
const overrideQty = ref({}); // decisionId -> string

const load = async () => {
    loading.value = true;
    try {
        rows.value = (await axios.get('/api/match-decisions?status=needs_review')).data.data;
    } catch (e) {
        handleApiError(e);
    } finally {
        loading.value = false;
    }
};
onMounted(load);

const review = async (decision, action) => {
    try {
        const payload = { action };
        if (action === 'approve' && overrideQty.value[decision.id]) {
            payload.authorized_qty = overrideQty.value[decision.id];
        }
        await axios.post(`/api/match-decisions/${decision.id}/review`, payload);
        pushToast(action === 'approve' ? 'Décision approuvée.' : 'Écart rejeté.', 'success');
        rows.value = rows.value.filter((r) => r.id !== decision.id);
    } catch (e) {
        handleApiError(e);
    }
};
</script>

<template>
    <Head title="Revue des écarts" />
    <h1 class="mb-1 text-lg font-semibold text-slate-900">Revue des écarts</h1>
    <p class="mb-4 text-sm text-slate-500">
        Décisions <em>needs_review</em> produites par le moteur.
        <span v-if="!isReviewer" class="text-amber-600">Lecture seule — vous n'êtes pas réviseur.</span>
    </p>

    <div v-if="loading" class="text-sm text-slate-400">Chargement…</div>
    <div v-else-if="!rows.length" class="rounded-xl border border-slate-200 bg-white p-6 text-center text-sm text-slate-400">
        Rien à revoir. 🎉
    </div>

    <div v-else class="space-y-3">
        <div v-for="d in rows" :key="d.id" class="rounded-xl border border-slate-200 bg-white p-4">
            <div class="flex items-start justify-between">
                <div>
                    <Link
                        v-if="d.invoice_line?.invoice"
                        :href="`/invoices/${d.invoice_line.invoice.id}`"
                        class="font-medium text-slate-800 hover:underline"
                    >
                        {{ d.invoice_line.invoice.reference }}
                    </Link>
                    <span class="ml-2 text-sm text-slate-500">
                        {{ d.invoice_line?.article_code }} — {{ d.invoice_line?.qty_invoiced }} × {{ d.invoice_line?.unit_price }}
                    </span>
                    <div class="mt-1 text-xs text-slate-500">rapprochable : {{ d.matchable_qty }}</div>
                </div>
            </div>

            <div class="mt-2"><Reasons :reasons="d.reasons" /></div>

            <div v-if="isReviewer" class="mt-3 flex items-center gap-2">
                <input
                    v-model="overrideQty[d.id]"
                    placeholder="qté (déf. rapprochable)"
                    class="w-44 rounded border border-slate-300 px-2 py-1 text-sm"
                />
                <button class="rounded bg-emerald-600 px-3 py-1 text-sm text-white hover:bg-emerald-700" @click="review(d, 'approve')">
                    Approuver
                </button>
                <button class="rounded bg-rose-600 px-3 py-1 text-sm text-white hover:bg-rose-700" @click="review(d, 'reject')">
                    Rejeter
                </button>
            </div>
        </div>
    </div>
</template>
