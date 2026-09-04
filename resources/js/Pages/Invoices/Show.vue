<script setup>
import { onMounted, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import StatusBadge from '../../Components/StatusBadge.vue';
import Reasons from '../../Components/Reasons.vue';
import { handleApiError } from '../../composables/api';
import { pushToast } from '../../composables/toast';

const props = defineProps({ id: { type: Number, required: true } });

const invoice = ref(null);
const loading = ref(true);
const matching = ref(false);
const history = ref({}); // invoiceLineId -> decisions[]

const load = async () => {
    try {
        invoice.value = (await axios.get(`/api/invoices/${props.id}`)).data.data;
    } catch (e) {
        handleApiError(e);
    } finally {
        loading.value = false;
    }
};
onMounted(load);

const runMatch = async () => {
    matching.value = true;
    try {
        invoice.value = (await axios.post(`/api/invoices/${props.id}/match`)).data.data;
        history.value = {};
        pushToast('Rapprochement effectué.', 'success');
    } catch (e) {
        handleApiError(e);
    } finally {
        matching.value = false;
    }
};

const toggleHistory = async (lineId) => {
    if (history.value[lineId]) {
        delete history.value[lineId];
        return;
    }
    try {
        history.value = { ...history.value, [lineId]: (await axios.get(`/api/invoice-lines/${lineId}/decisions`)).data.data };
    } catch (e) {
        handleApiError(e);
    }
};
</script>

<template>
    <Head :title="invoice ? invoice.reference : 'Facture'" />
    <Link href="/invoices" class="text-sm text-slate-500 hover:underline">← Factures</Link>

    <div v-if="loading" class="mt-4 text-sm text-slate-400">Chargement…</div>

    <div v-else-if="invoice" class="mt-2 space-y-5">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-lg font-semibold text-slate-900">{{ invoice.reference }} <StatusBadge :status="invoice.status" /></h1>
                <p class="text-sm text-slate-500">
                    PO
                    <Link :href="`/purchase-orders/${invoice.purchase_order_id}`" class="hover:underline">{{ invoice.purchase_order?.reference ?? invoice.purchase_order_id }}</Link>
                    — {{ invoice.invoice_date }} — fournisseur revendiqué #{{ invoice.supplier_id }}
                </p>
            </div>
            <button
                :disabled="matching"
                class="rounded-md bg-slate-900 px-3 py-1.5 text-sm font-medium text-white hover:bg-slate-800 disabled:opacity-50"
                @click="runMatch"
            >
                {{ matching ? 'Rapprochement…' : 'Déclencher le rapprochement' }}
            </button>
        </div>

        <div class="space-y-3">
            <div v-for="line in invoice.lines" :key="line.id" class="rounded-xl border border-slate-200 bg-white p-4">
                <div class="flex items-start justify-between">
                    <div>
                        <span class="font-medium text-slate-800">{{ line.article_code }}</span>
                        <span class="ml-2 text-xs text-slate-400">{{ line.purchase_order_line_id ? `ligne PO #${line.purchase_order_line_id}` : 'hors PO' }}</span>
                        <div class="text-sm text-slate-500">{{ line.qty_invoiced }} × {{ line.unit_price }}</div>
                    </div>
                    <div class="text-right">
                        <StatusBadge :status="line.decision?.status" />
                        <div v-if="line.decision" class="mt-1 text-sm text-slate-600">
                            autorisé : {{ line.decision.authorized_qty }} → <span class="font-medium">{{ line.decision.authorized_amount }}</span>
                        </div>
                        <div v-if="line.payment_authorization" class="text-xs text-emerald-600">
                            paiement {{ line.payment_authorization.status }} ({{ line.payment_authorization.authorized_amount }})
                        </div>
                    </div>
                </div>

                <div v-if="line.decision?.reasons?.length" class="mt-2">
                    <Reasons :reasons="line.decision.reasons" />
                </div>

                <div v-if="line.decision?.consumptions?.length" class="mt-2 text-xs text-slate-500">
                    FIFO :
                    <span v-for="c in line.decision.consumptions" :key="c.delivery_note_line_id">
                        ligne DN #{{ c.delivery_note_line_id }} ({{ c.qty_consumed }})
                    </span>
                </div>

                <button class="mt-3 text-xs text-slate-500 hover:underline" @click="toggleHistory(line.id)">
                    {{ history[line.id] ? 'Masquer' : 'Historique des décisions' }}
                </button>

                <ol v-if="history[line.id]" class="mt-2 space-y-2 border-l-2 border-slate-200 pl-3 text-xs">
                    <li v-for="d in history[line.id]" :key="d.id">
                        <div class="flex items-center gap-2">
                            <StatusBadge :status="d.status" />
                            <span v-if="d.is_current" class="rounded bg-slate-900 px-1 text-white">courante</span>
                            <span class="text-slate-400">
                                {{ d.actor_type === 'user' ? (d.actor_user?.name ?? 'réviseur') : 'moteur' }}
                                — {{ new Date(d.decided_at).toLocaleString('fr-FR') }}
                            </span>
                        </div>
                        <div class="text-slate-500">
                            rapprochable {{ d.matchable_qty }} · autorisé {{ d.authorized_qty }} → {{ d.authorized_amount }}
                            <span v-if="d.supersedes_id"> · remplace #{{ d.supersedes_id }}</span>
                        </div>
                        <Reasons :reasons="d.reasons" />
                    </li>
                </ol>
            </div>
        </div>
    </div>
</template>
