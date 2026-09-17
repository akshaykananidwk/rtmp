<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;
use App\Domain\Streaming\Engines\StreamEngineInterface;
use Symfony\Component\Process\ExecutableFinder;

class StreamingEngineCheck implements HealthCheckInterface
{
    public function __construct(private readonly StreamEngineInterface $engine) {}

    public function name(): string
    {
        return 'streaming_engine';
    }

    public function label(): string
    {
        return 'Streaming Engine';
    }

    public function critical(): bool
    {
        return false; // web app can run on hosting without the media server
    }

    public function run(): CheckResult
    {
        $ffmpeg = (string) config('akstream.streaming.ffmpeg', 'ffmpeg');
        $ffmpegPath = is_executable($ffmpeg) ? $ffmpeg : (new ExecutableFinder)->find($ffmpeg);
        $details = ['engine' => $this->engine->name(), 'api' => config('akstream.streaming.engine_api_url'), 'ffmpeg' => $ffmpegPath];

        if ($this->engine->name() === 'none') {
            return CheckResult::warn($this->name(), 'No streaming engine configured (web-only mode)', $details);
        }
        if (! $this->engine->isReachable()) {
            return CheckResult::fail($this->name(), 'MediaMTX API not reachable at '.config('akstream.streaming.engine_api_url'), $details);
        }
        $details['info'] = $this->engine->info();
        if (! $ffmpegPath) {
            return CheckResult::warn($this->name(), 'Engine reachable but ffmpeg binary not found', $details);
        }

        return CheckResult::pass($this->name(), 'MediaMTX reachable, ffmpeg found', $details);
    }
}
