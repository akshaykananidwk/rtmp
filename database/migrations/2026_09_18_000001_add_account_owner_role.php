<?php

declare(strict_types=1);

use Database\Seeders\RoleSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Installs the account_owner role that self-service sign-ups receive.
 *
 * The seeder is idempotent and re-syncs the canonical role→permission map, so this also
 * carries any other permission change in this release to existing installs.
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

        (new RoleSeeder)->run();
    }

    public function down(): void
    {
        // Nothing to undo: removing a role would only lock its holders out.
    }
};
