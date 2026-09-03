<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * Résultat qualitatif du rapprochement d'une ligne de facture (CONCEPTION.md §3).
 *
 *  - matched            : facturé = rapprochable, prix OK      -> autorise en totalité
 *  - partially_matched  : livré < facturé                      -> autorise la portion livrée
 *  - pending_receipt    : rien de disponible à rapprocher       -> autorise 0
 *  - needs_review       : écart > tolérance (prix, sur-facturation, fournisseur,
 *                         article hors PO)                       -> autorise 0, revue humaine
 *
 * Enum "backed string" : stocké tel quel dans match_decisions.status (M8, portabilité).
 */
enum MatchStatus: string
{
    case Matched = 'matched';
    case PartiallyMatched = 'partially_matched';
    case PendingReceipt = 'pending_receipt';
    case NeedsReview = 'needs_review';

    /** Une portion de paiement est-elle autorisée par ce statut ? */
    public function authorizesPayment(): bool
    {
        return $this === self::Matched || $this === self::PartiallyMatched;
    }
}
