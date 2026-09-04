<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import axios from 'axios';
import { handleApiError } from '../../composables/api';
import { pushToast } from '../../composables/toast';

const suppliers = ref([]);
const projects = ref([]);
const errors = ref({});
const saving = ref(false);

const form = reactive({
    reference: '',
    supplier_id: '',
    project_id: '',
    lines: [blankLine()],
});

function blankLine() {
    return { article_code: '', description: '', unit: 'u', qty_ordered: '', unit_price: '' };
}

onMounted(async () => {
    try {
        [suppliers.value, projects.value] = await Promise.all([
            axios.get('/api/suppliers').then((r) => r.data),
            axios.get('/api/projects').then((r) => r.data),
        ]);
    } catch (e) {
        handleApiError(e);
    }
});

const submit = async () => {
    saving.value = true;
    errors.value = {};
    try {
        const { data } = await axios.post('/api/purchase-orders', form);
        pushToast('Bon de commande créé.', 'success');
        router.visit(`/purchase-orders/${data.data.id}`);
    } catch (e) {
        errors.value = handleApiError(e);
    } finally {
        saving.value = false;
    }
};

const err = (key) => errors.value[key]?.[0];

// Aides d'affichage : total par ligne + total général (calcul client, lecture seule).
const fmt = (n) => new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 2 }).format(Number(n) || 0);
const lineTotal = (l) => (Number(l.qty_ordered) || 0) * (Number(l.unit_price) || 0);
const grandTotal = computed(() => form.lines.reduce((s, l) => s + lineTotal(l), 0));
</script>

<template>
    <Head title="Nouveau bon de commande" />

    <Link href="/purchase-orders" class="inline-flex items-center gap-1 text-sm text-slate-500 transition hover:text-slate-800">
        <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.7 4.3a1 1 0 010 1.4L8.4 10l4.3 4.3a1 1 0 11-1.4 1.4l-5-5a1 1 0 010-1.4l5-5a1 1 0 011.4 0z" clip-rule="evenodd" /></svg>
        Bons de commande
    </Link>
    <h1 class="mt-2 text-xl font-semibold text-slate-900">Nouveau bon de commande</h1>

    <form class="mt-6 space-y-5" @submit.prevent="submit">
        <!-- En-tête -->
        <div class="card p-5">
            <h2 class="mb-4 text-sm font-semibold text-slate-900">En-tête</h2>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="field-label">Référence</label>
                    <input v-model="form.reference" class="field-input" placeholder="PO-2026-001" />
                    <p v-if="err('reference')" class="mt-1 text-xs text-rose-600">{{ err('reference') }}</p>
                </div>
                <div>
                    <label class="field-label">Fournisseur</label>
                    <select v-model="form.supplier_id" class="field-input">
                        <option value="" disabled>Choisir…</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }} ({{ s.code }})</option>
                    </select>
                    <p v-if="err('supplier_id')" class="mt-1 text-xs text-rose-600">{{ err('supplier_id') }}</p>
                </div>
                <div>
                    <label class="field-label">Projet</label>
                    <select v-model="form.project_id" class="field-input">
                        <option value="" disabled>Choisir…</option>
                        <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }} ({{ p.code }})</option>
                    </select>
                    <p v-if="err('project_id')" class="mt-1 text-xs text-rose-600">{{ err('project_id') }}</p>
                </div>
            </div>
        </div>

        <!-- Lignes -->
        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                <h2 class="text-sm font-semibold text-slate-900">Lignes d'articles</h2>
                <button type="button" class="btn-ghost btn-sm" @click="form.lines.push(blankLine())">+ Ajouter une ligne</button>
            </div>
            <table class="w-full">
                <thead class="border-b border-slate-200 bg-slate-50/60">
                    <tr>
                        <th class="th">Article</th>
                        <th class="th">Description</th>
                        <th class="th">Unité</th>
                        <th class="th text-right">Qté</th>
                        <th class="th text-right">Prix unit.</th>
                        <th class="th text-right">Total</th>
                        <th class="th"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <tr v-for="(l, i) in form.lines" :key="i">
                        <td class="px-3 py-2"><input v-model="l.article_code" class="field-input" placeholder="CIM-42" /></td>
                        <td class="px-3 py-2"><input v-model="l.description" class="field-input" placeholder="Ciment CPA 42.5" /></td>
                        <td class="px-3 py-2"><input v-model="l.unit" class="field-input w-16" /></td>
                        <td class="px-3 py-2"><input v-model="l.qty_ordered" inputmode="decimal" class="field-input w-24 text-right" /></td>
                        <td class="px-3 py-2"><input v-model="l.unit_price" inputmode="decimal" class="field-input w-28 text-right" /></td>
                        <td class="tnum px-3 py-2 text-right text-sm font-medium text-slate-700">{{ fmt(lineTotal(l)) }}</td>
                        <td class="px-3 py-2 text-right">
                            <button
                                v-if="form.lines.length > 1"
                                type="button"
                                class="rounded-md p-1.5 text-slate-400 transition hover:bg-rose-50 hover:text-rose-600"
                                aria-label="Supprimer la ligne"
                                @click="form.lines.splice(i, 1)"
                            >
                                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6"><path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" /></svg>
                            </button>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="border-t border-slate-200 bg-slate-50/60">
                        <td colspan="5" class="px-4 py-2.5 text-right text-sm font-medium text-slate-500">Total commande</td>
                        <td class="tnum px-3 py-2.5 text-right text-sm font-semibold text-slate-900">{{ fmt(grandTotal) }}</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <p v-if="err('lines')" class="px-5 py-2 text-xs text-rose-600">{{ err('lines') }}</p>
        </div>

        <div class="flex items-center gap-3">
            <button type="submit" :disabled="saving" class="btn-primary">
                {{ saving ? 'Création…' : 'Créer le bon de commande' }}
            </button>
            <Link href="/purchase-orders" class="btn-ghost">Annuler</Link>
        </div>
    </form>
</template>
