<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Facture fournisseur. Plusieurs factures par PO ; facturation partielle permise (F3).
 *
 * CONCEPTION.md §2.1 :
 *   id PK · reference · purchase_order_id FK · supplier_id FK ·
 *   invoice_date(date) · status(submitted|partially_matched|matched|needs_review = submitted) ·
 *   currency · notes? · timestamps
 *   └ UNIQUE(supplier_id, reference)
 *
 * M3 — supplier_id = fournisseur REVENDIQUÉ par la facture, comparé à celui du PO (contrôle F8).
 *   La référence n'est unique que par fournisseur (deux fournisseurs peuvent réutiliser un n°).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('reference');

            $table->foreignId('purchase_order_id')
                ->constrained('purchase_orders')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('supplier_id')
                ->constrained('suppliers')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->date('invoice_date');
            $table->string('status')->default('submitted'); // submitted|partially_matched|matched|needs_review
            $table->char('currency', 3)->default('XOF');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['supplier_id', 'reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};
