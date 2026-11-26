<?php

namespace Tests\Support;

use Database\Seeders\DemoDataSeeder;

/**
 * DemoDataSeeder pointed at a temporary demo file, so the tests exercise the real
 * guard and seeder without touching database/anis-demo.sqlite or any other database.
 */
class DemoDataSeederOnFile extends DemoDataSeeder
{
    public static string $file = '';

    protected function databaseFile(): string
    {
        return self::$file;
    }
}
