<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * CareVMA uses its own roles table and users.role based authorization.
     *
     * The legacy project also ships Spatie's permission migration, but the
     * application does not use Spatie\Permission role/permission models.
     * Running the published Spatie migration collides with CareVMA's existing
     * `roles` table and with imported databases that may already contain a
     * `permissions` table. Keep this migration as a compatibility no-op so
     * Laravel can record it as completed without modifying or deleting legacy
     * authorization data.
     */
    public function up(): void
    {
        // Intentionally no-op.
    }

    /**
     * Never drop legacy roles/permissions tables from this compatibility
     * migration.
     */
    public function down(): void
    {
        // Intentionally no-op.
    }
};
