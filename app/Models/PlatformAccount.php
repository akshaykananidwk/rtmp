<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class PlatformAccount extends Model
{
    use BelongsToTenant;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'user_id', 'platform', 'external_id', 'name', 'avatar_url', 'meta', 'status'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function token(): HasOne
    {
        return $this->hasOne(PlatformToken::class)->latestOfMany();
    }

    public function tokens(): HasMany
    {
        return $this->hasMany(PlatformToken::class);
    }

    public function destinations(): HasMany
    {
        return $this->hasMany(StreamDestination::class);
    }
}
