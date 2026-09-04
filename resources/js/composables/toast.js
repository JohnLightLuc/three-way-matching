import { reactive } from 'vue';

export const toasts = reactive([]);

let seq = 0;

export function pushToast(message, type = 'error') {
    const toast = { id: ++seq, message, type };
    toasts.push(toast);
    setTimeout(() => {
        const i = toasts.findIndex((t) => t.id === toast.id);
        if (i !== -1) toasts.splice(i, 1);
    }, 5000);
}

export function dismissToast(id) {
    const i = toasts.findIndex((t) => t.id === id);
    if (i !== -1) toasts.splice(i, 1);
}
