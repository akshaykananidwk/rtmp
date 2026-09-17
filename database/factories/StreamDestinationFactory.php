<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Crypt;

class StreamDestinationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'platform' => 'custom_rtmp',
            'name' => fake()->words(2, true),
            'connection_method' => 'rtmp',
            'rtmp_url' => 'rtmp://live.example.com/app',
            'stream_key_encrypted' => Crypt::encryptString('secret-key-'.fake()->uuid()),
            'stream_key_hint' => 'abcd',
            'is_enabled' => true,
            'status' => 'idle',
        ];
    }
}
