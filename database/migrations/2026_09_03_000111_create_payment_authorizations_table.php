<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registre d'allocation COURANT — projection de la dernière décision autorisée (M5).
 * Anti double-paiement (F7) : une seule autorisation active par ligne de facture.
 *
 * CONCEPTION.md §2.1 :
 *   id PK · invoice_line_id FK · match_decision_id FK ·
 *   authorized_qty · authorized_amount(dec16,2) · status(authorized|revoked = authorized) · timestamps
 *   └ UNIQUE(invoice_line_id) SUR status = 'authorized'
 *
 * Portabilité de l'unique partiel :
 *   - SQLite / PostgreSQL : index unique filtré (WHERE status = 'authorized').
 *   - MySQL/MariaDB : pas d'index filtré -> l'unicité « une seule autorisation active »
 *     retombe sur la garde transactionnelle du service (règle 9 : lockForUpdate sur la
 *     ligne de PO). Documenté dans DECISIONS.md.
 *
 * PA reste modifiable (authorized -> revoked) : ce n'est pas un journal append-only,
 * contrairement à match_decisions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_authorizations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_line_id')
                ->constrained('invoice_lines')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->foreignId('match_decision_id')
                ->constrained('match_decisions')
                ->restrictOnUpdate()
                ->restrictOnDelete();

            $table->decimal('authorized_qty', 14, 3);
            $table->decimal('authorized_amount', 16, 2);
            $table->string('status')->default('authorized'); // authorized|revoked
            $table->timestamps();

            $table->index('invoice_line_id');
        });

        $driver = Schema::getConnection()->getDriverName();

        if (in_array($driver, ['sqlite', 'pgsql'], true)) {
            DB::statement(
                "CREATE UNIQUE INDEX payment_authorizations_active_unique "
                ."ON payment_authorizations (invoice_line_id) WHERE status = 'authorized'"
            );
        }
        // MySQL/MariaDB : cf. en-tête — unicité assurée côté service (règle 9).
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_authorizations');
    }
};
