import { pushToast } from './toast';

/** Traduit une erreur axios en toast lisible. Retourne les erreurs de validation éventuelles. */
export function handleApiError(error, fallback = 'Une erreur est survenue.') {
    const res = error?.response;

    if (!res) {
        pushToast('Serveur injoignable.', 'error');
        return {};
    }

    if (res.status === 422) {
        const errors = res.data?.errors ?? {};
        const first = Object.values(errors)[0]?.[0];
        pushToast(first ?? res.data?.message ?? 'Données invalides.', 'error');
        return errors;
    }

    if (res.status === 403) {
        pushToast('Action réservée aux réviseurs.', 'error');
    } else if (res.status === 401) {
        pushToast('Session expirée — reconnectez-vous.', 'error');
        window.location.href = '/login';
    } else if (res.status === 409) {
        pushToast(res.data?.message ?? 'Conflit : la décision a déjà été traitée.', 'error');
    } else {
        pushToast(res.data?.message ?? fallback, 'error');
    }

    return {};
}
