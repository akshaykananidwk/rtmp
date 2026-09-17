<?php

declare(strict_types=1);

namespace App\Domain\Streaming\Engines;

/** Used when no engine is configured (e.g. shared hosting running only the web app). */
class NullEngine implements StreamEngineInterface
{
    public function name(): string
    {
        return 'none';
    }

    public function isReachable(): bool
    {
        return false;
    }

    public function info(): array
    {
        return ['reachable' => false, 'error' => 'No streaming engine configured'];
    }

    public function listPaths(): array
    {
        return [];
    }

    public function getPath(string $path): ?array
    {
        return null;
    }

    public function kickPublisher(string $path): bool
    {
        return false;
    }

    public function internalSourceUrl(string $path): string
    {
        return rtrim((string) config('akstream.streaming.internal_rtmp_url'), '/').'/'.$path;
    }

    public function publicIngestUrl(): string
    {
        return (string) config('akstream.streaming.public_rtmp_url');
    }

    public function hlsUrl(string $path): string
    {
        return rtrim((string) config('akstream.streaming.hls_url'), '/').'/'.$path.'/index.m3u8';
    }
}
