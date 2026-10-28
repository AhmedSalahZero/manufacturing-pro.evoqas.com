<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// ══════════════════════════════════════════════════════════════════
//  ZAVERO Manufacturing Pro — Database seeder
//  Location: database/seeders/DatabaseSeeder.php
//  Run:  php artisan db:seed
// ══════════════════════════════════════════════════════════════════

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            AdminUserSeeder::class,
            BusinessSectorSeeder::class,
        ]);
    }
}
