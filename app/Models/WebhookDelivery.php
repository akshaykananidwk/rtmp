<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebhookDelivery extends Model
{
    use BelongsToTenant;
    use HasUlids;

    protected $fillable = ['outgoing_webhook_id', 'tenant_id', 'event', 'status_code', 'duration_ms', 'attempt', 'succeeded', 'error'];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean'];
    }

    public function webhook(): BelongsTo
    {
        return $this->belongsTo(OutgoingWebhook::class, 'outgoing_webhook_id');
    }
}
