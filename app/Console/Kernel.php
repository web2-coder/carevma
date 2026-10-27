<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Laravel 12 schedule definitions live in routes/console.php for this project.
        // Kept intentionally empty to prevent duplicate monthly invoice runs.
    }
}
