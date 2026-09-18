<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Streaming\Relay\FfmpegRelayProcess;
use Tests\TestCase;

class RelayErrorReportingTest extends TestCase
{
    public function test_the_reported_error_keeps_the_reason_and_hides_the_stream_key(): void
    {
        config(['akstream.streaming.ffmpeg' => base_path('tests/Fixtures/fake-ffmpeg-input-error.sh')]);

        $relay = new FfmpegRelayProcess('r1', 'relay', 'rtmp://127.0.0.1:1935/live/SUPERSECRETKEY', 'rtmp://out.example.com/app/target');
        $relay->start();
        while ($relay->isRunning()) {
            $relay->poll();
            usleep(20000);
        }
        $relay->poll();

        $error = $relay->lastError();

        // The line that actually identifies the fault used to be discarded.
        $this->assertStringContainsString('NetStream.Play.StreamNotFound', $error);
        $this->assertStringContainsString('Input/output error', $error);

        $this->assertStringNotContainsString('SUPERSECRETKEY', $error, 'a stream key must never reach a log or the UI');
        $this->assertStringContainsString('***', $error);
    }

    public function test_a_silent_failure_still_reports_the_exit_code(): void
    {
        config(['akstream.streaming.ffmpeg' => '/bin/false']);

        $relay = new FfmpegRelayProcess('r2', 'relay', 'rtmp://127.0.0.1:1935/live/k', 'rtmp://out.example.com/app/t');
        $relay->start();
        while ($relay->isRunning()) {
            usleep(20000);
        }
        $relay->poll();

        $this->assertStringContainsString('exited with code 1', $relay->lastError());
    }
}
