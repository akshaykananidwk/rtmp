<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use Illuminate\Support\Facades\Redis;

class RedisCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'redis';
    }

    public function label(): string
    {
        return 'Redis';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $inUse = in_array('redis', [config('cache.default'), config('queue.default'), config('session.driver')], true);
        if (! $inUse) {
            return CheckResult::pass($this->name(), 'Not in use (database drivers configured)', ['in_use' => false]);
        }
        try {
            $pong = Redis::connection()->ping();

            return CheckResult::pass($this->name(), 'PING OK', ['response' => (string) $pong]);
        } catch (\Throwable $e) {
            return CheckResult::fail($this->name(), 'Redis unreachable: '.$e->getMessage());
        }
    }
}
