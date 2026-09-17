<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Console\Command;

class MakeUserCommand extends Command
{
    protected $signature = 'user:make {email} {--name=Admin} {--password=} {--role=super_admin} {--tenant=}';

    protected $description = 'Create (or reset) a user from the console (emergency admin access)';

    public function handle(): int
    {
        $tenant = $this->option('tenant') ? Tenant::where('slug', $this->option('tenant'))->firstOrFail() : (Tenant::first() ?? Tenant::create(['name' => 'Default', 'slug' => 'default']));
        $password = $this->option('password') ?: $this->secret('Password');
        if (strlen((string) $password) < 10) {
            $this->error('Password must be at least 10 characters');

            return self::FAILURE;
        }
        $user = User::withoutGlobalScopes()->updateOrCreate(['email' => $this->argument('email')], ['tenant_id' => $tenant->id, 'name' => $this->option('name'), 'password' => $password, 'is_active' => true, 'email_verified_at' => now()]);
        $user->syncRole((string) $this->option('role'));
        $this->info('User '.$user->email.' ready with role '.$this->option('role'));

        return self::SUCCESS;
    }
}
