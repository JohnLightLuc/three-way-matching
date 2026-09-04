<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traçabilité de l'acteur des décisions : remplace la chaîne libre `actor_id`
 * (CONCEPTION.md §2.1) par une vraie FK `actor_user_id` -> users.
 *
 * - actor_type = 'system' -> actor_user_id NULL (décision du moteur).
 * - actor_type = 'user'   -> actor_user_id renseigné (révision humaine, F10).
 *   Cohérence garantie côté service (comme l'append-only) : SQLite n'offre pas
 *   de CHECK conditionnel portable.
 *
 * FK en restrictOnDelete (M8 : on ne supprime pas un utilisateur référencé par
 * une décision auditée).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_decisions', function (Blueprint $table) {
            $table->dropColumn('actor_id');
        });

        Schema::table('match_decisions', function (Blueprint $table) {
            $table->foreignId('actor_user_id')
                ->nullable()
                ->after('actor_type')
                ->constrained('users')
                ->restrictOnUpdate()
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('actor_user_id');
        });

        Schema::table('match_decisions', function (Blueprint $table) {
            $table->string('actor_id')->nullable()->after('actor_type');
        });
    }
};
