<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seeds nothing (ANIS-SEC-01, F3): "php artisan db:seed" and "migrate --seed"
     * create no accounts in any environment.
     *
     * Demo accounts live in DemoDataSeeder, which runs only on the dedicated local
     * demo database and is never called from here.
     */
    public function run(): void
    {
        //
    }
}
