<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;

class RtmpCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'rtmp';
    }

    public function label(): string
    {
        return 'RTMP';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $url = (string) config('akstream.streaming.internal_rtmp_url');
        $host = parse_url($url, PHP_URL_HOST) ?: '127.0.0.1';
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: 1935);
        $details = ['host' => $host, 'port' => $port, 'public_url' => config('akstream.streaming.public_rtmp_url')];

        if (config('akstream.streaming.engine') === 'none') {
            return CheckResult::warn($this->name(), 'RTMP ingest not configured on this host', $details);
        }

        $errno = 0;
        $errstr = '';
        try {
            $sock = @fsockopen($host, $port, $errno, $errstr, 3);
        } catch (\Throwable $e) {
            return CheckResult::warn($this->name(), 'Cannot test the RTMP port from PHP: '.$e->getMessage(), $details);
        }
        if (! $sock) {
            $details['hint'] = 'Install and start the streaming engine on this server (see docs/STREAMING_SETUP.md), then re-run the check. Until then the dashboard works but no stream can be ingested.';

            return CheckResult::fail($this->name(), "RTMP port $port not accepting connections ($errstr) – MediaMTX is not running", $details);
        }
        fclose($sock);

        return CheckResult::pass($this->name(), "Port $port open", $details);
    }
}
