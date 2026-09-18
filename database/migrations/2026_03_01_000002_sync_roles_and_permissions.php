<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Permissions are only created by the installer, so a release that introduces new
 * ones (overlays.* here) would leave every non-super-admin with a 403 forever.
 * Re-running the seeder is idempotent — it updates roles and re-syncs the
 * canonical role→permission map — so every future update picks new ones up too.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['roles', 'permissions', 'permission_role'] as $table) {
            if (! Schema::hasTable($table)) {
                return;
            }
        }

        (new RoleSeeder())->run();
    }

    public function down(): void
    {
        // Nothing to undo: removing permissions would only lock people out.
    }
};
