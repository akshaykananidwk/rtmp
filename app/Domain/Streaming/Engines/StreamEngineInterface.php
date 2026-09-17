<?php

declare(strict_types=1);

namespace App\Domain\Streaming\Engines;

/**
 * Abstraction over the media server (MediaMTX / SRS). The application never
 * handles RTMP bytes itself; it only queries and controls the engine.
 */
interface StreamEngineInterface
{
    public function name(): string;

    /** Is the engine control API reachable? */
    public function isReachable(): bool;

    /** Engine version / info for diagnostics. */
    public function info(): array;

    /** @return array<string, array{ready: bool, bytes_received: int, bytes_sent: int, readers: int, tracks: array, source_type: ?string}> */
    public function listPaths(): array;

    public function getPath(string $path): ?array;

    /** Forcefully disconnect the publisher of a path (kick the encoder). */
    public function kickPublisher(string $path): bool;

    /** Internal RTMP URL FFmpeg relays should read from. */
    public function internalSourceUrl(string $path): string;

    /** Public RTMP URL to show in OBS (without key). */
    public function publicIngestUrl(): string;

    public function hlsUrl(string $path): string;
}
