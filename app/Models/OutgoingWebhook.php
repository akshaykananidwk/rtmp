<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class OutgoingWebhook extends Model
{
    use BelongsToTenant;
    use HasUlids;

    /** Disabled automatically after this many consecutive failures, so a dead endpoint stops costing us. */
    public const FAILURE_LIMIT = 20;

    protected $fillable = ['tenant_id', 'name', 'url', 'events', 'is_enabled'];

    protected $hidden = ['secret_encrypted'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'is_enabled' => 'boolean',
            'disabled_at' => 'datetime',
            'last_delivered_at' => 'datetime',
        ];
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function setSecret(string $secret): void
    {
        $this->secret_encrypted = Crypt::encryptString($secret);
        $this->secret_hint = mb_substr($secret, 0, 4).'…'.mb_substr($secret, -2);
    }

    public function secret(): string
    {
        return Crypt::decryptString($this->secret_encrypted);
    }

    public function listensFor(string $event): bool
    {
        return $this->is_enabled && in_array($event, $this->events ?? [], true);
    }
}
