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

    public function test_a_too_slow_encoder_is_explained_not_just_reported_as_a_broken_pipe(): void
    {
        config(['akstream.streaming.ffmpeg' => base_path('tests/Fixtures/fake-ffmpeg-slow.sh')]);

        $relay = new FfmpegRelayProcess('b1', 'brand', 'rtmp://127.0.0.1:1935/live/k', 'rtmp://127.0.0.1:1935/branded/k');
        $relay->start();
        while ($relay->isRunning()) {
            $relay->poll();
            usleep(20000);
        }
        $relay->poll();

        $this->assertSame(0.61, $relay->speed());
        $this->assertSame(17, $relay->fps());

        $note = $relay->slownessNote();
        $this->assertNotNull($note, 'below real time the operator needs to be told why');
        $this->assertStringContainsString('0.61x real time', $note);
        $this->assertStringContainsString('17 fps', $note);
        $this->assertStringContainsString('Lower the overlay resolution', $note);
    }

    public function test_an_encoder_keeping_up_gets_no_slowness_note(): void
    {
        config(['akstream.streaming.ffmpeg' => base_path('tests/Fixtures/fake-ffmpeg.sh')]);

        $relay = new FfmpegRelayProcess('b2', 'brand', 'rtmp://127.0.0.1:1935/live/k', 'rtmp://127.0.0.1:1935/branded/k');
        $relay->start();
        usleep(400000);
        $relay->poll();
        $relay->stop();

        $this->assertNull($relay->slownessNote());
    }
}
