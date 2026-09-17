<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use Illuminate\Support\Facades\Cache;

class CacheCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'cache';
    }

    public function label(): string
    {
        return 'Cache';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        try {
            $key = 'health:'.bin2hex(random_bytes(4));
            Cache::put($key, 'ok', 30);
            $v = Cache::get($key);
            Cache::forget($key);
            $details = ['store' => config('cache.default')];

            return $v === 'ok' ? CheckResult::pass($this->name(), 'Store: '.config('cache.default'), $details) : CheckResult::fail($this->name(), 'Cache read-back mismatch', $details);
        } catch (\Throwable $e) {
            return CheckResult::fail($this->name(), $e->getMessage());
        }
    }
}
