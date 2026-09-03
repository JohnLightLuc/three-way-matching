<script setup>
import { computed, watch } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { toasts, dismissToast, pushToast } from '../composables/toast';

const page = usePage();
const user = computed(() => page.props.auth?.user);

// `icon` = nom d'une clé du bloc <template> d'icônes ci-dessous.
const nav = [
    { href: '/', label: 'Tableau de bord', icon: 'grid' },
    { href: '/purchase-orders', label: 'Bons de commande', icon: 'doc' },
    { href: '/invoices', label: 'Factures', icon: 'receipt' },
    { href: '/review', label: 'Revue des écarts', icon: 'alert' },
];

const isActive = (href) => (href === '/' ? page.url === '/' : page.url.startsWith(href));

watch(
    () => page.props.flash?.error,
    (msg) => msg && pushToast(msg, 'error'),
    { immediate: true },
);

const logout = () => router.post('/logout');
</script>

<template>
    <!-- Jeu d'icônes SVG réutilisé par la nav (référencé via <use>). -->
    <svg class="hidden" aria-hidden="true">
        <defs>
            <g id="ic-grid"><path d="M3 3h7v7H3zM14 3h7v7h-7zM14 14h7v7h-7zM3 14h7v7H3z" /></g>
            <g id="ic-doc"><path d="M6 2h8l4 4v16H6zM14 2v4h4" /></g>
            <g id="ic-receipt"><path d="M5 2h14v20l-3-2-2 2-2-2-2 2-2-2-3 2zM8 7h8M8 11h8M8 15h5" /></g>
            <g id="ic-alert"><path d="M12 3l9 16H3zM12 10v4M12 17h.01" /></g>
        </defs>
    </svg>

    <div class="min-h-screen">
        <!-- Barre latérale (desktop) -->
        <aside class="fixed inset-y-0 left-0 z-40 hidden w-60 flex-col bg-ink-950 lg:flex">
            <div class="flex items-center gap-2.5 px-5 py-5">
                <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-brand-600 text-sm font-bold text-white">3</span>
                <span class="text-sm font-semibold leading-tight text-white">Rapprochement<br />3&nbsp;voies</span>
            </div>

            <nav class="mt-2 flex-1 space-y-0.5 px-3">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="group flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition"
                    :class="isActive(item.href) ? 'bg-white/10 font-medium text-white' : 'text-slate-300/80 hover:bg-white/5 hover:text-white'"
                >
                    <svg
                        class="h-[18px] w-[18px] shrink-0"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.7"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        :class="isActive(item.href) ? 'text-brand-400' : 'text-slate-400 group-hover:text-slate-200'"
                    >
                        <use :href="`#ic-${item.icon}`" />
                    </svg>
                    {{ item.label }}
                </Link>
            </nav>

            <div v-if="user" class="border-t border-white/10 px-4 py-4">
                <div class="flex items-center gap-2 text-sm text-slate-200">
                    <span class="flex h-8 w-8 items-center justify-center rounded-full bg-white/10 text-xs font-semibold uppercase text-white">
                        {{ (user.name || '?').slice(0, 1) }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-slate-100">{{ user.name }}</div>
                        <div v-if="user.is_reviewer" class="text-xs text-brand-400">Réviseur</div>
                    </div>
                </div>
                <button class="mt-3 w-full rounded-lg px-3 py-1.5 text-left text-sm text-slate-400 transition hover:bg-white/5 hover:text-white" @click="logout">
                    Déconnexion
                </button>
            </div>
        </aside>

        <!-- Barre supérieure (mobile) -->
        <header class="sticky top-0 z-40 bg-ink-950 lg:hidden">
            <div class="flex items-center justify-between px-4 py-3">
                <span class="flex items-center gap-2 text-sm font-semibold text-white">
                    <span class="flex h-6 w-6 items-center justify-center rounded-md bg-brand-600 text-xs font-bold">3</span>
                    Rapprochement 3&nbsp;voies
                </span>
                <button v-if="user" class="text-sm text-slate-400 hover:text-white" @click="logout">Déconnexion</button>
            </div>
            <nav class="flex gap-1 overflow-x-auto px-3 pb-2">
                <Link
                    v-for="item in nav"
                    :key="item.href"
                    :href="item.href"
                    class="whitespace-nowrap rounded-lg px-3 py-1.5 text-sm transition"
                    :class="isActive(item.href) ? 'bg-white/10 font-medium text-white' : 'text-slate-300/80 hover:bg-white/5'"
                >
                    {{ item.label }}
                </Link>
            </nav>
        </header>

        <!-- Contenu -->
        <main class="lg:pl-60">
            <div class="mx-auto max-w-6xl px-4 py-8 sm:px-6 lg:px-10">
                <slot />
            </div>
        </main>

        <!-- Toasts -->
        <TransitionGroup
            tag="div"
            class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-80 flex-col gap-2"
            enter-active-class="transition duration-200 ease-out"
            enter-from-class="translate-x-4 opacity-0"
            enter-to-class="translate-x-0 opacity-100"
            leave-active-class="transition duration-150 ease-in"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-for="t in toasts"
                :key="t.id"
                class="pointer-events-auto flex items-start gap-2.5 rounded-xl border-l-4 bg-white px-3.5 py-3 text-sm shadow-lg ring-1 ring-black/5"
                :class="t.type === 'success' ? 'border-emerald-500' : 'border-rose-500'"
            >
                <svg
                    class="mt-0.5 h-4 w-4 shrink-0"
                    :class="t.type === 'success' ? 'text-emerald-500' : 'text-rose-500'"
                    viewBox="0 0 20 20"
                    fill="currentColor"
                >
                    <path
                        v-if="t.type === 'success'"
                        fill-rule="evenodd"
                        d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.7-9.3a1 1 0 00-1.4-1.4L9 10.6 7.7 9.3a1 1 0 00-1.4 1.4l2 2a1 1 0 001.4 0z"
                        clip-rule="evenodd"
                    />
                    <path
                        v-else
                        fill-rule="evenodd"
                        d="M8.3 3.9a2 2 0 013.4 0l6 10.5A2 2 0 0116 17.5H4a2 2 0 01-1.7-3.1zM10 7a1 1 0 00-1 1v3a1 1 0 002 0V8a1 1 0 00-1-1zm0 8a1 1 0 100-2 1 1 0 000 2z"
                        clip-rule="evenodd"
                    />
                </svg>
                <span class="flex-1 text-slate-700">{{ t.message }}</span>
                <button class="text-slate-400 transition hover:text-slate-600" @click="dismissToast(t.id)">✕</button>
            </div>
        </TransitionGroup>
    </div>
</template>
