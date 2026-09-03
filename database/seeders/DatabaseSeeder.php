<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed la base : utilisateurs de démo, puis jeu de démonstration du moteur
     * de rapprochement 3 voies.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
