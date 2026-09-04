<script setup>
import { computed } from 'vue';

const props = defineProps({ status: { type: String, default: null } });

// [libellé, tonalité] — la tonalité pilote couleur de fond, texte, anneau et point.
const MAP = {
    matched: ['Rapproché', 'emerald'],
    partially_matched: ['Partiel', 'amber'],
    pending_receipt: ['En attente', 'slate'],
    needs_review: ['À revoir', 'rose'],
    submitted: ['Soumise', 'slate'],
    open: ['Ouvert', 'sky'],
    closed: ['Clos', 'slate'],
    cancelled: ['Annulé', 'slate'],
    draft: ['Brouillon', 'slate'],
    authorized: ['Autorisée', 'emerald'],
    revoked: ['Révoquée', 'slate'],
};

// Classes écrites en toutes lettres pour que Tailwind les détecte (pas de concaténation).
const TONE = {
    emerald: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
    amber: 'bg-amber-50 text-amber-800 ring-amber-600/20',
    rose: 'bg-rose-50 text-rose-700 ring-rose-600/20',
    slate: 'bg-slate-100 text-slate-600 ring-slate-500/20',
    sky: 'bg-sky-50 text-sky-700 ring-sky-600/20',
};
const DOT = {
    emerald: 'bg-emerald-500',
    amber: 'bg-amber-500',
    rose: 'bg-rose-500',
    slate: 'bg-slate-400',
    sky: 'bg-sky-500',
};

const entry = computed(() => MAP[props.status] ?? [props.status ?? '—', 'slate']);
const label = computed(() => entry.value[0]);
const tone = computed(() => entry.value[1]);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium ring-1 ring-inset"
        :class="TONE[tone]"
    >
        <span class="h-1.5 w-1.5 rounded-full" :class="DOT[tone]" />
        {{ label }}
    </span>
</template>
