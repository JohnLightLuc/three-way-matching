<?php

declare(strict_types=1);

namespace App\Domain\Matching;

use Brick\Math\BigDecimal;

/**
 * Entrée du moteur pour l'évaluation d'UNE ligne de facture (règle : rapprochement
 * par ligne d'article). Données simples uniquement — aucune connaissance d'Eloquent
 * ni de la base (règle 8). Le service (3c) construit cet objet dans une transaction
 * avec verrou sur la ligne de PO (règle 9).
 *
 * RÉ-ÉVALUATION (F9, idempotence) : quand on rejoue une ligne de facture déjà
 * décidée, sa décision courante devient superseded et son allocation est libérée
 * (règle 7 / M10). Le service DOIT donc exclure cette décision du pool :
 * `qtyAlreadyMatched` et `ReceiptData::qtyAlreadyConsumed` ne comptent que les
 * décisions autorisées des AUTRES lignes de facture. Le moteur, lui, évalue
 * toujours la ligne « à neuf » contre le pool qu'on lui donne.
 */
final readonly class MatchInput
{
    public BigDecimal $qtyAlreadyMatched;

    /** @var list<ReceiptData> */
    public array $receipts;

    /**
     * @param  OrderLineData|null  $orderLine  null si la ligne de facture n'est rattachée à aucune ligne de PO (M2).
     * @param  int  $poSupplierId  Fournisseur du PO auquel la facture est rattachée (toujours connu, F8).
     * @param  int  $claimedSupplierId  Fournisseur revendiqué par la facture (invoices.supplier_id, M3).
     * @param  BigDecimal|string|int|float  $qtyAlreadyMatched  Σ authorized_qty des décisions actuellement autorisées sur la ligne de PO.
     * @param  list<ReceiptData>  $receipts  Lignes de DN de la ligne de PO (ordre indifférent : le moteur trie en FIFO).
     */
    public function __construct(
        public ?OrderLineData $orderLine,
        public int $poSupplierId,
        public InvoiceLineData $invoiceLine,
        public int $claimedSupplierId,
        BigDecimal|string|int|float $qtyAlreadyMatched,
        array $receipts,
        public Tolerances $tolerances,
    ) {
        $this->qtyAlreadyMatched = Decimal::of($qtyAlreadyMatched);
        $this->receipts = array_values($receipts);
    }
}
