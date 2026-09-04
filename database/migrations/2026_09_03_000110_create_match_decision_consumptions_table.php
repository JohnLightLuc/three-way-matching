<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trace relationnelle « décision ↔ livraisons consommées » (M9).
 * Table de liaison porteuse de quantité : une décision consomme PLUSIEURS lignes de DN,
 * en FIFO par delivery_notes.received_at (M10). Append-only comme match_decisions.
 *
 * CONCEPTION.md §2.1 :
 *   id PK · match_decision_id FK · delivery_note_line_id FK · qty_consumed(dec14,3) · timestamps
 *   └ UNIQUE(match_decision_id, delivery_note_line_id)
 *   └ CHECK qty_consumed > 0   (posé par 2026_09_03_000112_add_check_constraints)
 *
 * Invariants vérifiables (règle 6 / M9) :
 *   - Σ qty_consumed d'une décision            = son authorized_qty ;
 *   - Σ qty_consumed sur une ligne de DN       ≤ sa qty_received  (anti double-paiement
 *     au grain de la livraison ; ne compte que les décisions actuellement autorisées).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_decision_consumptions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('match_decision_id')
                ->constrained('match_decisions')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('delivery_note_line_id')
                ->constrained('delivery_note_lines')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->decimal('qty_consumed', 14, 3);
            $table->timestamps();

            // Nom explicite : l'auto-généré dépasse la limite de 64 caractères de MySQL.
            $table->unique(['match_decision_id', 'delivery_note_line_id'], 'mdc_decision_dn_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_decision_consumptions');
    }
};
