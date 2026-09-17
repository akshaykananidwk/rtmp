<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Streaming\StreamKeyService;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

class StreamEndpointFactory extends Factory
{
    public function definition(): array
    {
        $key = 'AKDWK-'.Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8)).'-'.Str::upper(Str::random(8));

        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake()->words(2, true),
            'slug' => Str::upper(Str::random(10)),
            'key_hash' => StreamKeyService::hash($key),
            'key_encrypted' => Crypt::encryptString($key),
            'key_hint' => substr($key, -4),
            'is_enabled' => true,
        ];
    }
}
