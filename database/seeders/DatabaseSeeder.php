<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Deliberately empty: never inject test accounts on generic db:seed.
        // For local-only explicit seeding, use:
        // php artisan db:seed --class=CareVmaOneEmployeeOneClientSeeder
    }
}
