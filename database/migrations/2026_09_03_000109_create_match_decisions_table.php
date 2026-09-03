<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Journal d'audit des décisions du moteur — APPEND-ONLY (règle 3 / M5).
 * Jamais d'UPDATE ni de DELETE : une révision humaine = nouvelle ligne
 * (actor_type = user) liée par supersedes_id à la décision remplacée (F10).
 * L'append-only est garanti côté modèle (events updating/deleting -> exception).
 *
 * CONCEPTION.md §2.1 :
 *   id PK · invoice_line_id FK · purchase_order_line_id FK? ·
 *   status(MatchStatus) · matchable_qty · authorized_qty · authorized_amount(dec16,2) ·
 *   price_delta_pct(dec8,4)? · reasons(json) · actor_type(system|user) · actor_id? ·
 *   decided_at(datetime) · inputs_snapshot(json) · supersedes_id FK→match_decisions? · timestamps
 *
 * Règle 4  — authorized_amount = authorized_qty × prix PO (jamais le prix facturé).
 * Règle 10 — inputs_snapshot fige qtés, prix PO/facturé, tolérances appliquées, DN consommés :
 *            décision reproductible même si les seuils changent ensuite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_decisions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_line_id')
                ->constrained('invoice_lines')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('purchase_order_line_id')
                ->nullable()
                ->constrained('purchase_order_lines')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->string('status'); // matched|partially_matched|pending_receipt|needs_review

            $table->decimal('matchable_qty', 14, 3);
            $table->decimal('authorized_qty', 14, 3);
            $table->decimal('authorized_amount', 16, 2);
            $table->decimal('price_delta_pct', 8, 4)->nullable();

            $table->json('reasons');

            $table->string('actor_type'); // system|user
            $table->string('actor_id')->nullable();
            $table->dateTime('decided_at');

            $table->json('inputs_snapshot');

            $table->foreignId('supersedes_id')
                ->nullable()
                ->constrained('match_decisions')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_decisions');
    }
};
