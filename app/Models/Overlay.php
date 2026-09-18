<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A branding layout: the lines of text, clock and logo that are burned into the
 * outgoing stream, news-channel style.
 */
class Overlay extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use HasUlids;
    use SoftDeletes;

    protected $fillable = ['tenant_id', 'name', 'elements', 'resolution', 'bitrate_kbps', 'fps', 'preset', 'is_default'];

    protected function casts(): array
    {
        return ['elements' => 'array', 'is_default' => 'boolean'];
    }

    /** @return array<int, array<string, mixed>> */
    public function elements(): array
    {
        return array_values(array_filter($this->elements ?? [], fn ($e) => ($e['enabled'] ?? true) && ! empty($e['type'])));
    }

    public function width(): int
    {
        return (int) (explode('x', $this->resolution)[0] ?? 1920);
    }

    public function height(): int
    {
        return (int) (explode('x', $this->resolution)[1] ?? 1080);
    }
}
