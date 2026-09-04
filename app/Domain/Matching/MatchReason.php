<?php

declare(strict_types=1);

namespace App\Domain\Matching;

/**
 * Motifs attachés à une décision (colonne match_decisions.reasons, JSON).
 *
 * Les quatre premiers sont des ANOMALIES (règle 5) : dès qu'un seul est présent,
 * le statut est needs_review et rien n'est autorisé. Les deux derniers sont
 * informatifs : ils expliquent une ligne saine mais incomplète (règle 2).
 */
enum MatchReason: string
{
    /** Ligne de facture sans rattachement à une ligne de PO (FK nulle — M2). */
    case ArticleNotInPurchaseOrder = 'article_not_in_po';

    /** Fournisseur revendiqué par la facture ≠ fournisseur du PO (F8). */
    case SupplierMismatch = 'supplier_mismatch';

    /** Quantité facturée > quantité encore disponible sur la commande (F7). */
    case OverInvoiced = 'over_invoiced';

    /** Écart de prix unitaire au-delà de la tolérance (F6). */
    case PriceOutOfTolerance = 'price_out_of_tolerance';

    /** Livré < facturé : la portion livrée est autorisée, le reste attend. */
    case PartialReceipt = 'partial_receipt';

    /** Rien de disponible à rapprocher (aucun arrivage, ou stock déjà consommé). */
    case NothingReceived = 'nothing_received';

    /** Un réviseur a autorisé la ligne malgré l'écart signalé (F10). */
    case ReviewApproved = 'review_approved';

    /** Un réviseur a confirmé l'écart : la ligne reste non autorisée (F10). */
    case ReviewRejected = 'review_rejected';

    public function isAnomaly(): bool
    {
        return match ($this) {
            self::ArticleNotInPurchaseOrder,
            self::SupplierMismatch,
            self::OverInvoiced,
            self::PriceOutOfTolerance => true,
            self::PartialReceipt,
            self::NothingReceived,
            self::ReviewApproved,
            self::ReviewRejected => false,
        };
    }
}
