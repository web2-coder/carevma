<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')
            ->whereNotNull('date_of_joining')
            ->whereNull('probation_end_date')
            ->orderBy('id')
            ->chunkById(200, function ($employees): void {
                foreach ($employees as $employee) {
                    DB::table('employees')
                        ->where('id', $employee->id)
                        ->update([
                            'probation_end_date' => Carbon::parse($employee->date_of_joining)->addMonths(3)->toDateString(),
                            'is_probation_email_sent' => false,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // This is an intentional data backfill; do not erase valid probation dates on rollback.
    }
};
