import './bootstrap';
import '../css/app.css';

import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';
import AppLayout from './Layouts/AppLayout.vue';

createInertiaApp({
    title: (title) => (title ? `${title} · Rapprochement 3 voies` : 'Rapprochement 3 voies'),

    resolve: (name) => {
        const pages = import.meta.glob('./Pages/**/*.vue', { eager: true });
        const page = pages[`./Pages/${name}.vue`];

        // Toutes les pages sont enveloppées par AppLayout, sauf l'écran de connexion.
        page.default.layout = page.default.layout ?? (name.startsWith('Auth/') ? null : AppLayout);

        return page;
    },

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .mount(el);
    },

    progress: { color: '#2563eb' },
});
