<script setup>
import { computed } from 'vue';

const props = defineProps({ reasons: { type: Array, default: () => [] } });

const LABELS = {
    article_not_in_po: 'Article absent du PO',
    supplier_mismatch: 'Fournisseur incohérent',
    over_invoiced: 'Sur-facturation',
    price_out_of_tolerance: 'Prix hors tolérance',
    partial_receipt: 'Livraison partielle',
    nothing_received: 'Rien reçu',
    review_approved: 'Approuvé par un réviseur',
    review_rejected: 'Rejeté par un réviseur',
};

// Sévérité → couleur du chip (classes en toutes lettres pour Tailwind).
const DANGER = ['article_not_in_po', 'supplier_mismatch', 'over_invoiced', 'price_out_of_tolerance', 'review_rejected'];
const OK = ['review_approved'];

const tone = (code) => {
    if (DANGER.includes(code)) return 'bg-rose-50 text-rose-700 ring-rose-600/20';
    if (OK.includes(code)) return 'bg-emerald-50 text-emerald-700 ring-emerald-600/20';
    return 'bg-slate-100 text-slate-600 ring-slate-500/20';
};

const ctx = (r) =>
    r.context && Object.keys(r.context).length
        ? Object.entries(r.context)
              .map(([k, v]) => `${k}: ${v}`)
              .join(' · ')
        : '';

const list = computed(() => props.reasons ?? []);
</script>

<template>
    <div v-if="list.length" class="flex flex-wrap gap-1.5">
        <span
            v-for="(r, i) in list"
            :key="i"
            class="inline-flex items-center gap-1 rounded-md px-2 py-0.5 text-xs font-medium ring-1 ring-inset"
            :class="tone(r.code)"
        >
            {{ LABELS[r.code] ?? r.code }}
            <span v-if="ctx(r)" class="font-normal opacity-70">— {{ ctx(r) }}</span>
        </span>
    </div>
    <span v-else class="text-xs text-slate-400">—</span>
</template>
