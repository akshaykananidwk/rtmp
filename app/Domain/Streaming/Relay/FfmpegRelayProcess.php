<?php

declare(strict_types=1);

namespace App\Domain\Streaming\Relay;

use App\Support\SecretMasker;
use Symfony\Component\Process\Process;

/**
 * One FFmpeg process copying the ingest stream to a single RTMP target
 * (or to a recording file). Wraps Symfony Process for async control.
 */
final class FfmpegRelayProcess
{
    private Process $process;

    private string $progressBuffer = '';

    private int $bytesOut = 0;

    private int $lastBytes = 0;

    private float $lastBytesAt = 0.0;

    private int $bitrateKbps = 0;

    /** Encoding speed relative to real time, from ffmpeg's own progress output. */
    private float $speed = 0.0;

    private int $fps = 0;

    private string $stderrTail = '';

    public function __construct(
        public readonly string $id,
        public readonly string $kind, // relay | record | brand
        private readonly string $sourceUrl,
        private readonly string $targetUrl,
        private readonly array $extraArgs = [],
    ) {
        $bin = (string) config('akstream.streaming.ffmpeg', 'ffmpeg');
        $isFile = $kind === 'record';
        $isBranding = $kind === 'brand';

        $cmd = [
            $bin, '-hide_banner', '-nostdin', '-loglevel', 'warning', '-nostats',
            '-rw_timeout', '15000000',
            // Without a decent input queue a momentary encoder stall blocks the socket read,
            // and the media server drops us as a slow reader.
            '-thread_queue_size', '1024',
            '-i', $this->sourceUrl,
        ];

        if ($isBranding) {
            // Overlay pass: re-encode once, everything else copies the result.
            // extraArgs carries the extra inputs, filter graph and encoder settings.
            $cmd = array_merge($cmd, $this->extraArgs, ['-f', 'flv', '-flvflags', 'no_duration_filesize']);
        } else {
            $cmd[] = '-c';
            $cmd[] = 'copy';

            if ($isFile) {
                $cmd = array_merge($cmd, ['-movflags', '+faststart+frag_keyframe+empty_moov', '-f', 'mp4']);
            } else {
                $cmd = array_merge($cmd, ['-bsf:a', 'aac_adtstoasc', '-f', 'flv', '-flvflags', 'no_duration_filesize']);
            }

            $cmd = array_merge($cmd, $this->extraArgs);
        }

        $cmd = array_merge($cmd, ['-progress', 'pipe:1', $this->targetUrl]);

        $this->process = new Process($cmd);
        $this->process->setTimeout(null);
        $this->process->setIdleTimeout(null);
    }

    public function start(): void
    {
        $this->lastBytesAt = microtime(true);
        $this->process->start();
    }

    /** The stream this process was started against; used to notice the source changing. */
    public function sourceUrl(): string
    {
        return $this->sourceUrl;
    }

    public function pid(): ?int
    {
        return $this->process->getPid();
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function exitCode(): ?int
    {
        return $this->process->getExitCode();
    }

    /** Read incremental progress output. Returns true if bytes advanced. */
    public function poll(): bool
    {
        $out = $this->process->getIncrementalOutput();
        $err = $this->process->getIncrementalErrorOutput();
        if ($err !== '') {
            $this->stderrTail = substr($this->stderrTail.$err, -2000);
        }
        if ($out === '') {
            return false;
        }

        $this->progressBuffer .= $out;
        $advanced = false;
        foreach (explode("\n", $this->progressBuffer) as $line) {
            if (str_starts_with($line, 'total_size=')) {
                $v = (int) substr($line, 11);
                if ($v > $this->bytesOut) {
                    $this->bytesOut = $v;
                    $advanced = true;
                }
            } elseif (str_starts_with($line, 'speed=')) {
                // "speed=0.61x" — below 1x the encoder cannot keep up with the live source,
                // and the media server eventually drops it as a slow reader.
                $value = trim(rtrim(trim(substr($line, 6)), 'x'));
                if (is_numeric($value)) {
                    $this->speed = (float) $value;
                }
            } elseif (str_starts_with($line, 'fps=')) {
                $this->fps = (int) round((float) substr($line, 4));
            }
        }
        // keep only unterminated tail
        $pos = strrpos($this->progressBuffer, "\n");
        $this->progressBuffer = $pos === false ? $this->progressBuffer : substr($this->progressBuffer, $pos + 1);

        $now = microtime(true);
        if ($now - $this->lastBytesAt >= 2.0) {
            $delta = $this->bytesOut - $this->lastBytes;
            $this->bitrateKbps = (int) round(($delta * 8 / 1000) / max(0.001, $now - $this->lastBytesAt));
            $this->lastBytes = $this->bytesOut;
            $this->lastBytesAt = $now;
        }

        return $advanced;
    }

    public function bytesOut(): int
    {
        return $this->bytesOut;
    }

    public function bitrateKbps(): int
    {
        return max(0, $this->bitrateKbps);
    }

    /** Encoding speed relative to real time; 0.0 when ffmpeg has not reported one yet. */
    public function speed(): float
    {
        return $this->speed;
    }

    public function fps(): int
    {
        return $this->fps;
    }

    /**
     * Plain-language note when the host cannot encode this stream in real time.
     *
     * A too-slow encoder is dropped by the media server, which surfaces as an unhelpful
     * "Broken pipe" — the number below is what actually explains it.
     */
    public function slownessNote(): ?string
    {
        if ($this->speed <= 0.0 || $this->speed >= 0.95) {
            return null;
        }

        return 'The server encoded at '.rtrim(rtrim(number_format($this->speed, 2), '0'), '.').'x real time'
            .($this->fps > 0 ? ' ('.$this->fps.' fps)' : '')
            .' — too slow to keep up, so the media server dropped it. Lower the overlay resolution, bitrate or preset, or use a faster CPU.';
    }

    public function lastError(): string
    {
        $lines = array_values(array_unique(array_filter(
            array_map('trim', explode("\n", $this->stderrTail)),
            fn (string $line) => $line !== '',
        )));

        if ($lines === []) {
            return SecretMasker::maskString('ffmpeg exited with code '.($this->exitCode() ?? '?'));
        }

        // FFmpeg prints the real reason first and a generic trailer last, e.g.
        //   [rtmp @ …] Server error: NetStream.Play.StreamNotFound
        //   Error opening input files: Input/output error
        // Reporting only the last line threw away the part that identifies the fault.
        $msg = implode(' | ', array_slice($lines, -3));

        // The masker turns rtmp://host/live/<key> into rtmp://host/live/*** — keep it,
        // because these lines quote the URL and must never expose a stream key.
        return SecretMasker::maskString(mb_substr($msg, 0, 500));
    }

    public function stop(float $timeout = 5.0): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop($timeout, defined('SIGTERM') ? SIGTERM : 15);
        }
    }
}
