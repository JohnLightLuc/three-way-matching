<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Utilisateurs de démonstration (mot de passe : « password »).
 * Rôles CONCEPTION.md §1.1 — pour l'instant seul « réviseur » est distingué
 * (is_reviewer) ; les autres partagent les mêmes droits sur PO / DN / factures.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Achats Démo',
            'email' => 'buyer@demo.test',
        ]);

        User::factory()->create([
            'name' => 'Comptabilité Démo',
            'email' => 'clerk@demo.test',
        ]);

        User::factory()->reviewer()->create([
            'name' => 'Contrôleur Démo',
            'email' => 'reviewer@demo.test',
        ]);
    }
}
