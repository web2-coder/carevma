<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Safe default: do not inject demo users into an existing/production database.
        if (filter_var(env('CARE_VMA_DEMO_SEED', false), FILTER_VALIDATE_BOOL)) {
            $this->call(CareVmaDemoSeeder::class);
        }
    }
}
