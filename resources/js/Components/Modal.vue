<script setup>
import { watch } from 'vue';

const props = defineProps({ show: Boolean, title: { type: String, default: '' } });
const emit = defineEmits(['close']);

// Fermeture au clavier (Échap) tant que la modale est ouverte.
const onKey = (e) => e.key === 'Escape' && emit('close');
watch(
    () => props.show,
    (open) => {
        if (typeof window === 'undefined') return;
        open ? window.addEventListener('keydown', onKey) : window.removeEventListener('keydown', onKey);
    },
);
</script>

<template>
    <Teleport to="body">
        <Transition
            enter-active-class="transition duration-150 ease-out"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition duration-100 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="show"
                class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-ink-950/50 p-4 pt-16 backdrop-blur-sm"
                @click="emit('close')"
            >
                <div
                    class="w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-2xl ring-1 ring-black/5"
                    @click.stop
                >
                    <div class="flex items-center justify-between border-b border-slate-200 px-5 py-3.5">
                        <h3 class="text-sm font-semibold text-slate-900">{{ title }}</h3>
                        <button
                            class="rounded-md p-1 text-slate-400 transition hover:bg-slate-100 hover:text-slate-700"
                            aria-label="Fermer"
                            @click="emit('close')"
                        >
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="none" stroke="currentColor" stroke-width="1.6">
                                <path d="M5 5l10 10M15 5L5 15" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>
                    <div class="max-h-[70vh] overflow-y-auto px-5 py-4">
                        <slot />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
