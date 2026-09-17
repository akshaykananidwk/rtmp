<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Models\Role;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\Notification;

/** Sends a system notification to all admins (optionally of a single tenant). */
class Notifier
{
    public function admins(string $type, string $title, string $message, string $level = 'info', ?string $tenantId = null, ?string $url = null, array $meta = []): void
    {
        $users = User::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereHas('roles', fn ($q) => $q->whereIn('name', [Role::SUPER_ADMIN, Role::ADMIN]))
            ->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new SystemNotification($type, $title, $message, $level, $url, $meta));
    }
}
