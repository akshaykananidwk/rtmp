<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\OutgoingWebhook;
use App\Models\User;
use App\Policies\Concerns\TenantOwned;

/**
 * Webhooks carry an account's own events to its own systems, so they sit with the rest
 * of the destination configuration rather than needing a permission of their own.
 */
class OutgoingWebhookPolicy
{
    use TenantOwned;

    public function viewAny(User $user): bool
    {
        return $user->hasPermission('destinations.view');
    }

    public function view(User $user, OutgoingWebhook $webhook): bool
    {
        return $this->sameTenant($user, $webhook) && $user->hasPermission('destinations.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('destinations.manage');
    }

    public function update(User $user, OutgoingWebhook $webhook): bool
    {
        return $this->sameTenant($user, $webhook) && $user->hasPermission('destinations.manage');
    }

    public function delete(User $user, OutgoingWebhook $webhook): bool
    {
        return $this->update($user, $webhook);
    }
}
