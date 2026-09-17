<?php

declare(strict_types=1);

namespace App\Domain\Health;

use App\Domain\Health\Checks\ApplicationCheck;
use App\Domain\Health\Checks\CacheCheck;
use App\Domain\Health\Checks\DatabaseCheck;
use App\Domain\Health\Checks\GitHubCheck;
use App\Domain\Health\Checks\PhpExtensionsCheck;
use App\Domain\Health\Checks\QueueCheck;
use App\Domain\Health\Checks\RedisCheck;
use App\Domain\Health\Checks\RtmpCheck;
use App\Domain\Health\Checks\SslCheck;
use App\Domain\Health\Checks\StorageCheck;
use App\Domain\Health\Checks\StreamingEngineCheck;
use App\Events\SystemAlert;
use App\Models\HealthCheck;
use App\Support\SecretMasker;
use Illuminate\Support\Str;

class HealthService
{
    /** @var class-string<HealthCheckInterface>[] */
    private array $checks = [
        ApplicationCheck::class,
        DatabaseCheck::class,
        CacheCheck::class,
        RedisCheck::class,
        QueueCheck::class,
        StorageCheck::class,
        PhpExtensionsCheck::class,
        StreamingEngineCheck::class,
        RtmpCheck::class,
        SslCheck::class,
        GitHubCheck::class,
    ];

    /** @return HealthCheckInterface[] */
    public function checks(): array
    {
        return array_map(fn ($c) => app($c), $this->checks);
    }

    /**
     * Run all checks, persist a report, return it.
     *
     * @return array{run_id:string, ok:bool, critical_ok:bool, results:CheckResult[], summary:array}
     */
    public function run(string $trigger = 'manual', bool $persist = true, bool $alert = false): array
    {
        $runId = 'HC-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(4));
        $results = [];
        $ok = true;
        $criticalOk = true;

        foreach ($this->checks() as $check) {
            $start = microtime(true);
            try {
                $result = $check->run();
            } catch (\Throwable $e) {
                $result = CheckResult::fail($check->name(), 'Check crashed: '.SecretMasker::maskString($e->getMessage()));
            }
            $result->durationMs = (int) round((microtime(true) - $start) * 1000);
            $results[] = $result;

            if ($result->status === 'fail') {
                $ok = false;
                if ($check->critical()) {
                    $criticalOk = false;
                }
                if ($alert) {
                    event(new SystemAlert('health.'.$check->name(), $check->label().' is failing', $result->message, $check->critical() ? 'critical' : 'warning'));
                }
            }

            if ($persist) {
                HealthCheck::create([
                    'run_id' => $runId,
                    'service' => $result->service,
                    'status' => $result->status,
                    'message' => mb_substr($result->message, 0, 1000),
                    'details' => $result->details,
                    'duration_ms' => $result->durationMs,
                    'trigger' => $trigger,
                    'created_at' => now(),
                ]);
            }
        }

        return [
            'run_id' => $runId,
            'ok' => $ok,
            'critical_ok' => $criticalOk,
            'results' => $results,
            'summary' => [
                'pass' => count(array_filter($results, fn ($r) => $r->status === 'pass')),
                'warn' => count(array_filter($results, fn ($r) => $r->status === 'warn')),
                'fail' => count(array_filter($results, fn ($r) => $r->status === 'fail')),
            ],
        ];
    }

    /** Latest persisted result per service. */
    public function latest(): array
    {
        $rows = HealthCheck::query()->orderByDesc('id')->limit(200)->get();
        $latest = [];
        foreach ($rows as $row) {
            if (! isset($latest[$row->service])) {
                $latest[$row->service] = $row;
            }
        }

        return $latest;
    }

    public function labelFor(string $service): string
    {
        foreach ($this->checks() as $c) {
            if ($c->name() === $service) {
                return $c->label();
            }
        }

        return ucfirst($service);
    }

    public function report(array $run): string
    {
        $lines = [];
        foreach ($run['results'] as $r) {
            $lines[] = str_pad($this->labelFor($r->service), 18).strtoupper($r->status).($r->status !== 'pass' ? '  '.$r->message : '');
        }

        return implode("\n", $lines);
    }
}
