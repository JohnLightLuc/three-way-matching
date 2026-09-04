<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import axios from 'axios';
import StatusBadge from '../../Components/StatusBadge.vue';
import Modal from '../../Components/Modal.vue';
import { handleApiError } from '../../composables/api';
import { pushToast } from '../../composables/toast';

const props = defineProps({ id: { type: Number, required: true } });

const po = ref(null);
const loading = ref(true);
const errors = ref({});

const load = async () => {
    try {
        po.value = (await axios.get(`/api/purchase-orders/${props.id}`)).data.data;
    } catch (e) {
        handleApiError(e);
    } finally {
        loading.value = false;
    }
};
onMounted(load);

/* ---- Bon de livraison ---- */
const dnOpen = ref(false);
const dn = reactive({ reference: '', received_at: '', lines: [] });
const openDn = () => {
    Object.assign(dn, { reference: '', received_at: new Date().toISOString().slice(0, 10), lines: po.value.lines.map((l) => ({ purchase_order_line_id: l.id, qty_received: '' })) });
    errors.value = {};
    dnOpen.value = true;
};
const saveDn = async () => {
    try {
        await axios.post(`/api/purchase-orders/${props.id}/delivery-notes`, {
            reference: dn.reference,
            received_at: dn.received_at,
            lines: dn.lines.filter((l) => l.qty_received !== '' && Number(l.qty_received) > 0),
        });
        dnOpen.value = false;
        pushToast('Bon de livraison enregistré.', 'success');
        await load();
    } catch (e) {
        errors.value = handleApiError(e);
    }
};

/* ---- Facture ---- */
const invOpen = ref(false);
const suppliers = ref([]);
const inv = reactive({ reference: '', supplier_id: '', invoice_date: '', lines: [] });
const openInv = async () => {
    if (!suppliers.value.length) suppliers.value = (await axios.get('/api/suppliers')).data;
    Object.assign(inv, {
        reference: '',
        supplier_id: po.value.supplier?.id ?? '',
        invoice_date: new Date().toISOString().slice(0, 10),
        lines: po.value.lines.map((l) => ({ purchase_order_line_id: l.id, article_code: l.article_code, description: l.description, qty_invoiced: '', unit_price: l.unit_price })),
    });
    errors.value = {};
    invOpen.value = true;
};
const addOffPoLine = () => inv.lines.push({ purchase_order_line_id: null, article_code: '', description: '', qty_invoiced: '', unit_price: '' });
const saveInv = async () => {
    try {
        await axios.post(`/api/purchase-orders/${props.id}/invoices`, {
            reference: inv.reference,
            supplier_id: inv.supplier_id,
            invoice_date: inv.invoice_date,
            lines: inv.lines.filter((l) => l.qty_invoiced !== '' && Number(l.qty_invoiced) > 0),
        });
        invOpen.value = false;
        pushToast('Facture soumise.', 'success');
        await load();
    } catch (e) {
        errors.value = handleApiError(e);
    }
};

const err = (key) => errors.value[key]?.[0];
const supplierName = computed(() => po.value?.supplier?.name ?? '');

// Aides d'affichage.
const fmt = (n) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 3 }).format(Number(n) || 0);
const pct = (part, whole) => {
    const w = Number(whole) || 0;
    if (!w) return 0;
    return Math.min(100, Math.max(0, (Number(part) / w) * 100));
};
</script>

<template>
    <Head :title="po ? po.reference : 'Bon de commande'" />

    <Link href="/purchase-orders" class="inline-flex items-center gap-1 text-sm text-slate-500 transition hover:text-slate-800">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.7 4.3a1 1 0 010 1.4L8.4 10l4.3 4.3a1 1 0 11-1.4 1.4l-5-5a1 1 0 010-1.4l5-5a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
        Bons de commande
    </Link>

    <div v-if="loading" class="mt-6 space-y-4">
        <div class="h-16 animate-pulse rounded-xl bg-slate-100" />
        <div class="h-56 animate-pulse rounded-xl bg-slate-100" />
    </div>

    <div v-else-if="po" class="mt-3 space-y-6">
        <!-- En-tête -->
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-xl font-semibold text-slate-900">{{ po.reference }}</h1>
                    <StatusBadge :status="po.status" />
                </div>
                <dl class="mt-2 flex flex-wrap gap-x-6 gap-y-1 text-sm">
                    <div class="flex gap-1.5"><dt class="text-slate-400">Fournisseur</dt><dd class="font-medium text-slate-700">{{ supplierName }}</dd></div>
                    <div class="flex gap-1.5"><dt class="text-slate-400">Projet</dt><dd class="font-medium text-slate-700">{{ po.project?.name }}</dd></div>
                    <div class="flex gap-1.5"><dt class="text-slate-400">Devise</dt><dd class="font-medium text-slate-700">{{ po.currency }}</dd></div>
                </dl>
            </div>
            <div class="flex gap-2">
                <button class="btn-ghost btn-sm" @click="openDn">+ Bon de livraison</button>
                <button class="btn-primary btn-sm" @click="openInv">+ Facture</button>
            </div>
        </div>

        <!-- Registre de réconciliation -->
        <section>
            <h2 class="mb-2 text-sm font-semibold text-slate-900">Lignes</h2>
            <div class="card overflow-hidden">
                <table class="w-full">
                    <thead class="border-b border-slate-200 bg-slate-50/60">
                        <tr>
                            <th class="th w-8">#</th>
                            <th class="th">Article</th>
                            <th class="th text-right">Commandé</th>
                            <th class="th text-right">Reçu</th>
                            <th class="th text-right">Rapproché</th>
                            <th class="th text-right">Disponible</th>
                            <th class="th text-right">Prix unit.</th>
                            <th class="th w-36">Avancement</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="l in po.lines" :key="l.id" class="hover:bg-slate-50/60">
                            <td class="px-4 py-3 text-sm text-slate-400">{{ l.line_no }}</td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-slate-800">{{ l.article_code }}</div>
                                <div class="text-xs text-slate-400">{{ l.description }}</div>
                            </td>
                            <td class="tnum px-4 py-3 text-right text-sm text-slate-600">{{ fmt(l.qty_ordered) }} <span class="text-slate-400">{{ l.unit }}</span></td>
                            <td class="tnum px-4 py-3 text-right text-sm text-slate-600">{{ fmt(l.qty_received) }}</td>
                            <td class="tnum px-4 py-3 text-right text-sm text-slate-600">{{ fmt(l.qty_matched) }}</td>
                            <td class="tnum px-4 py-3 text-right text-sm font-semibold text-slate-900">{{ fmt(l.qty_available) }}</td>
                            <td class="tnum px-4 py-3 text-right text-sm text-slate-600">{{ fmt(l.unit_price) }}</td>
                            <td class="px-4 py-3">
                                <div
                                    class="relative h-1.5 w-28 overflow-hidden rounded-full bg-slate-100"
                                    :title="`Reçu ${fmt(l.qty_received)}/${fmt(l.qty_ordered)} · Rapproché ${fmt(l.qty_matched)}`"
                                >
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-sky-400" :style="{ width: pct(l.qty_received, l.qty_ordered) + '%' }" />
                                    <div class="absolute inset-y-0 left-0 rounded-full bg-emerald-500" :style="{ width: pct(l.qty_matched, l.qty_ordered) + '%' }" />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-emerald-500" />Rapproché</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-sky-400" />Reçu</span>
                <span class="inline-flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-slate-200" />Commandé restant</span>
            </div>
        </section>

        <!-- Livraisons + Factures -->
        <section class="grid gap-5 lg:grid-cols-2">
            <div>
                <h2 class="mb-2 text-sm font-semibold text-slate-900">Bons de livraison</h2>
                <div class="card p-4 text-sm">
                    <p v-if="!po.delivery_notes?.length" class="py-4 text-center text-slate-400">Aucune livraison enregistrée.</p>
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="d in po.delivery_notes" :key="d.id" class="py-3 first:pt-0 last:pb-0">
                            <div class="flex items-center justify-between">
                                <span class="font-medium text-slate-800">{{ d.reference }}</span>
                                <span class="tnum text-xs text-slate-400">{{ d.received_at }}</span>
                            </div>
                            <ul class="mt-1.5 space-y-0.5">
                                <li v-for="dl in d.lines" :key="dl.id" class="flex justify-between text-xs text-slate-500">
                                    <span>Ligne PO #{{ dl.purchase_order_line_id }}</span>
                                    <span class="tnum font-medium text-slate-700">{{ fmt(dl.qty_received) }}</span>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </div>
            <div>
                <h2 class="mb-2 text-sm font-semibold text-slate-900">Factures</h2>
                <div class="card p-4 text-sm">
                    <p v-if="!po.invoices?.length" class="py-4 text-center text-slate-400">Aucune facture soumise.</p>
                    <ul v-else class="divide-y divide-slate-100">
                        <li v-for="i in po.invoices" :key="i.id" class="flex items-center justify-between py-3 first:pt-0 last:pb-0">
                            <Link :href="`/invoices/${i.id}`" class="font-medium text-slate-800 transition hover:text-brand-700">{{ i.reference }}</Link>
                            <StatusBadge :status="i.status" />
                        </li>
                    </ul>
                </div>
            </div>
        </section>
    </div>

    <!-- Modal bon de livraison -->
    <Modal :show="dnOpen" title="Nouveau bon de livraison" @close="dnOpen = false">
        <div class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="field-label">Référence</label>
                    <input v-model="dn.reference" class="field-input" placeholder="BL-2026-001" />
                    <p v-if="err('reference')" class="mt-1 text-xs text-rose-600">{{ err('reference') }}</p>
                </div>
                <div>
                    <label class="field-label">Date de réception</label>
                    <input v-model="dn.received_at" type="date" class="field-input" />
                </div>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-200">
                <table class="w-full">
                    <thead class="border-b border-slate-200 bg-slate-50/60">
                        <tr><th class="th">Ligne PO</th><th class="th text-right">Qté reçue</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(l, i) in dn.lines" :key="i">
                            <td class="px-4 py-2 text-sm text-slate-600">{{ po.lines[i]?.article_code }} <span class="text-slate-400">(#{{ l.purchase_order_line_id }})</span></td>
                            <td class="px-3 py-2"><input v-model="l.qty_received" inputmode="decimal" class="field-input w-32 text-right" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end gap-2 pt-1">
                <button class="btn-ghost btn-sm" @click="dnOpen = false">Annuler</button>
                <button class="btn-primary btn-sm" @click="saveDn">Enregistrer</button>
            </div>
        </div>
    </Modal>

    <!-- Modal facture -->
    <Modal :show="invOpen" title="Nouvelle facture" @close="invOpen = false">
        <div class="space-y-4">
            <div class="grid grid-cols-3 gap-3">
                <div>
                    <label class="field-label">Référence</label>
                    <input v-model="inv.reference" class="field-input" placeholder="FA-2026-001" />
                    <p v-if="err('reference')" class="mt-1 text-xs text-rose-600">{{ err('reference') }}</p>
                </div>
                <div>
                    <label class="field-label">Fournisseur revendiqué</label>
                    <select v-model="inv.supplier_id" class="field-input">
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="field-label">Date</label>
                    <input v-model="inv.invoice_date" type="date" class="field-input" />
                </div>
            </div>
            <div class="overflow-hidden rounded-lg border border-slate-200">
                <table class="w-full">
                    <thead class="border-b border-slate-200 bg-slate-50/60">
                        <tr><th class="th">Article</th><th class="th">Ligne PO</th><th class="th text-right">Qté</th><th class="th text-right">Prix</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-for="(l, i) in inv.lines" :key="i">
                            <td class="px-3 py-2"><input v-model="l.article_code" class="field-input" /></td>
                            <td class="px-4 py-2 text-xs">
                                <span v-if="l.purchase_order_line_id" class="text-slate-500">#{{ l.purchase_order_line_id }}</span>
                                <span v-else class="rounded bg-amber-50 px-1.5 py-0.5 font-medium text-amber-700 ring-1 ring-inset ring-amber-600/20">hors PO</span>
                            </td>
                            <td class="px-3 py-2"><input v-model="l.qty_invoiced" inputmode="decimal" class="field-input w-24 text-right" /></td>
                            <td class="px-3 py-2"><input v-model="l.unit_price" inputmode="decimal" class="field-input w-28 text-right" /></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="text-sm font-medium text-brand-700 transition hover:text-brand-600" @click="addOffPoLine">+ Ligne hors PO</button>
            <div class="flex justify-end gap-2 pt-1">
                <button class="btn-ghost btn-sm" @click="invOpen = false">Annuler</button>
                <button class="btn-primary btn-sm" @click="saveInv">Soumettre</button>
            </div>
        </div>
    </Modal>
</template>
