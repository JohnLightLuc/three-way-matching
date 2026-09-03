<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed la base : jeu de démonstration du moteur de rapprochement 3 voies.
     * (Pas d'utilisateur : l'authentification est hors périmètre — CONCEPTION.md §4.)
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
