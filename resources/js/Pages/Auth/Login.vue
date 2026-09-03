<script setup>
import { useForm } from '@inertiajs/vue3';
import { Head } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '', remember: false });

const submit = () => form.post('/login', { onFinish: () => form.reset('password') });

// Confort de démo : remplit les identifiants d'un rôle en un clic.
const roles = [
    { email: 'buyer@demo.test', label: 'Acheteur' },
    { email: 'clerk@demo.test', label: 'Comptable' },
    { email: 'reviewer@demo.test', label: 'Réviseur' },
];
const fill = (email) => {
    form.email = email;
    form.password = 'password';
};
</script>

<template>
    <Head title="Connexion" />

    <div class="grid min-h-screen lg:grid-cols-2">
        <!-- Panneau de marque (desktop) -->
        <div class="relative hidden flex-col justify-between overflow-hidden bg-ink-950 p-10 text-white lg:flex">
            <div class="flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-base font-bold">3</span>
                <span class="text-sm font-semibold leading-tight">Rapprochement<br />3&nbsp;voies</span>
            </div>

            <div class="max-w-sm">
                <h2 class="text-2xl font-semibold leading-snug tracking-tight">
                    Un paiement n'est autorisé que lorsque trois documents concordent.
                </h2>
                <p class="mt-3 text-sm text-slate-300/80">
                    Bon de commande, bon de livraison et facture, confrontés ligne à ligne. Les écarts sont signalés pour revue, jamais réglés en silence.
                </p>

                <!-- Mini-schéma du flux -->
                <div class="mt-8 flex items-center gap-2 text-xs">
                    <span class="rounded-md bg-white/10 px-2.5 py-1 font-medium">Commande</span>
                    <span class="text-slate-500">+</span>
                    <span class="rounded-md bg-white/10 px-2.5 py-1 font-medium">Livraison</span>
                    <span class="text-slate-500">+</span>
                    <span class="rounded-md bg-white/10 px-2.5 py-1 font-medium">Facture</span>
                    <span class="text-brand-400">→</span>
                    <span class="rounded-md bg-emerald-500/20 px-2.5 py-1 font-medium text-emerald-300 ring-1 ring-inset ring-emerald-400/30">Paiement</span>
                </div>
            </div>

            <p class="text-xs text-slate-500">Contrôle anti-fraude · ERP BTP</p>
        </div>

        <!-- Formulaire -->
        <div class="flex items-center justify-center bg-slate-50 px-4 py-12">
            <div class="w-full max-w-sm">
                <div class="mb-8 lg:hidden">
                    <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-base font-bold text-white">3</span>
                </div>

                <h1 class="text-xl font-semibold text-slate-900">Connexion</h1>
                <p class="mt-1 text-sm text-slate-500">Accédez à l'espace de rapprochement.</p>

                <form class="mt-6 space-y-4" @submit.prevent="submit">
                    <div>
                        <label class="field-label">E-mail</label>
                        <input v-model="form.email" type="email" autocomplete="username" class="field-input" placeholder="vous@entreprise.test" />
                        <p v-if="form.errors.email" class="mt-1 text-xs text-rose-600">{{ form.errors.email }}</p>
                    </div>

                    <div>
                        <label class="field-label">Mot de passe</label>
                        <input v-model="form.password" type="password" autocomplete="current-password" class="field-input" placeholder="••••••••" />
                        <p v-if="form.errors.password" class="mt-1 text-xs text-rose-600">{{ form.errors.password }}</p>
                    </div>

                    <label class="flex items-center gap-2 text-sm text-slate-600">
                        <input v-model="form.remember" type="checkbox" class="rounded border-slate-300 text-brand-600 focus:ring-brand-600/30" />
                        Se souvenir de moi
                    </label>

                    <button type="submit" :disabled="form.processing" class="btn-primary w-full">
                        {{ form.processing ? 'Connexion…' : 'Se connecter' }}
                    </button>
                </form>

                <!-- Comptes de démonstration -->
                <div class="mt-6 rounded-xl border border-slate-200 bg-white p-4">
                    <p class="text-xs font-medium text-slate-500">Comptes de démonstration — cliquez pour remplir</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button
                            v-for="r in roles"
                            :key="r.email"
                            type="button"
                            class="rounded-lg border border-slate-200 bg-slate-50 px-2.5 py-1 text-xs font-medium text-slate-700 transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700"
                            @click="fill(r.email)"
                        >
                            {{ r.label }}
                        </button>
                    </div>
                    <p class="mt-2 text-xs text-slate-400">Mot de passe : <span class="font-medium text-slate-500">password</span></p>
                </div>
            </div>
        </div>
    </div>
</template>
