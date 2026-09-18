<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class OverlayFactory extends Factory
{
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => 'News style',
            'resolution' => '1280x720',
            'bitrate_kbps' => 2500,
            'fps' => 30,
            'preset' => 'ultrafast',
            'elements' => [
                ['type' => 'box', 'enabled' => true, 'position' => 'bottom_left', 'color' => '#000000', 'opacity' => 0.7, 'width' => 'iw', 'height' => 80, 'margin' => 0],
                ['type' => 'text', 'enabled' => true, 'text' => 'AK COMPUTER LIVE', 'position' => 'bottom_left', 'size' => 36, 'color' => '#ffffff', 'margin' => 30],
                ['type' => 'ticker', 'enabled' => true, 'text' => 'Breaking news from Dwarka', 'position' => 'bottom_left', 'size' => 24, 'color' => '#22d3ee', 'margin' => 10, 'speed' => 100],
                ['type' => 'clock', 'enabled' => true, 'format' => 'H:i:s', 'position' => 'top_right', 'size' => 28, 'color' => '#ffffff', 'margin' => 20],
            ],
        ];
    }
}
