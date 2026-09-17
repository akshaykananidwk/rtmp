<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class QueueCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'queue';
    }

    public function label(): string
    {
        return 'Queue';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $driver = config('queue.default');
        $details = ['driver' => $driver];

        if ($driver === 'sync') {
            return CheckResult::warn($this->name(), 'Queue runs synchronously (no worker); fine for shared hosting, not for streaming', $details);
        }

        try {
            if ($driver === 'database' && Schema::hasTable('jobs')) {
                $details['pending'] = DB::table('jobs')->count();
                $oldest = DB::table('jobs')->min('created_at');
                $details['oldest_pending_age_s'] = $oldest ? now()->timestamp - (int) $oldest : 0;
                if (Schema::hasTable('failed_jobs')) {
                    $details['failed_last_24h'] = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();
                }
            }
            $heartbeat = Cache::get('queue:heartbeat');
            $details['worker_heartbeat'] = $heartbeat;
            if ($heartbeat && now()->timestamp - (int) $heartbeat > 300) {
                return CheckResult::warn($this->name(), 'No queue worker heartbeat for '.(now()->timestamp - (int) $heartbeat).'s', $details);
            }
            if (($details['oldest_pending_age_s'] ?? 0) > 600) {
                return CheckResult::warn($this->name(), 'Jobs are waiting > 10 min – is the worker running?', $details);
            }

            return CheckResult::pass($this->name(), 'Driver: '.$driver, $details);
        } catch (\Throwable $e) {
            return CheckResult::fail($this->name(), $e->getMessage(), $details);
        }
    }
}
