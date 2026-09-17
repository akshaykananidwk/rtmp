<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TenantFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->company();

        return ['name' => $name, 'slug' => Str::slug($name).'-'.Str::lower(Str::random(4)), 'is_active' => true];
    }
}
