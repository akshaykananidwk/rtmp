<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\UpdateProtectedPath;
use Illuminate\Database\Seeder;

class ProtectedPathSeeder extends Seeder
{
    public function run(): void
    {
        foreach ((array) config('akstream.updates.protected_paths', []) as $path) {
            UpdateProtectedPath::firstOrCreate(['path' => $path], ['is_default' => true, 'note' => 'Default protected path']);
        }
    }
}
