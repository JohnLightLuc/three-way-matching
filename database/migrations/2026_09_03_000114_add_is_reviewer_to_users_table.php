<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rôle « réviseur » (Contrôleur / réviseur — CONCEPTION.md §1.1) : booléen simple
 * pour démarrer. Seul un utilisateur is_reviewer peut trancher un écart
 * (POST /api/match-decisions/{decision}/review).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_reviewer')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_reviewer');
        });
    }
};
