<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UpdateProtectedPath extends Model
{
    protected $fillable = ['path', 'is_default', 'note'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
