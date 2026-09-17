<?php

declare(strict_types=1);

namespace App\Domain\Destinations;

use App\Models\StreamDestination;
use App\Models\StreamSessionDestination;

/**
 * Every platform implements this connector contract. The core streaming
 * engine only ever talks to this interface, never to a platform directly.
 */
interface StreamingDestinationInterface
{
    /** Machine name, e.g. youtube, facebook, custom_rtmp. */
    public function platform(): string;

    /** Static definition: label, capabilities, form fields, official-API notes. */
    public function definition(): PlatformDefinition;

    /** Bind this connector instance to a destination record. */
    public function connect(StreamDestination $destination): static;

    /** Release platform resources (revoke tokens etc.). */
    public function disconnect(): void;

    /** Validate stored configuration/credentials. Returns [ok => bool, message => string]. */
    public function validate(): array;

    /** Platform-side preparation before the relay starts (create broadcast / live video). */
    public function startStream(StreamSessionDestination $sessionDestination): void;

    /** Platform-side teardown after the relay stops (end broadcast). */
    public function stopStream(StreamSessionDestination $sessionDestination): void;

    /** Current platform status for the destination. */
    public function getStatus(?StreamSessionDestination $sessionDestination = null): array;

    /** Non-secret stream information (watch URL, broadcast id, ingest host). */
    public function getStreamInfo(?StreamSessionDestination $sessionDestination = null): array;

    /** Full RTMP(S) target URL with key that FFmpeg should push to. Null when not resolvable. */
    public function rtmpTarget(StreamSessionDestination $sessionDestination): ?string;

    /** Whether startStream() must complete before the relay is spawned. */
    public function requiresPreparation(): bool;

    /** Hook: relay confirmed bytes are flowing (e.g. transition YouTube broadcast to live). */
    public function onRelayLive(StreamSessionDestination $sessionDestination): void;
}
