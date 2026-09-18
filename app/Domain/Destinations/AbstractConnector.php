<?php

declare(strict_types=1);

namespace App\Domain\Destinations;

use App\Models\StreamDestination;
use App\Models\StreamSessionDestination;

abstract class AbstractConnector implements StreamingDestinationInterface
{
    protected ?StreamDestination $destination = null;

    public function connect(StreamDestination $destination): static
    {
        $this->destination = $destination;

        return $this;
    }

    public function disconnect(): void
    {
        // default: nothing to release
    }

    public function requiresPreparation(): bool
    {
        return false;
    }

    public function startStream(StreamSessionDestination $sessionDestination): void
    {
        // default: plain RTMP push needs no preparation
    }

    public function stopStream(StreamSessionDestination $sessionDestination): void {}

    public function onRelayLive(StreamSessionDestination $sessionDestination): void {}

    public function getStatus(?StreamSessionDestination $sessionDestination = null): array
    {
        return [
            'status' => $sessionDestination?->status ?? $this->destination?->displayStatus() ?? 'idle',
            'last_error' => $sessionDestination?->last_error ?? $this->destination?->last_error,
        ];
    }

    public function getStreamInfo(?StreamSessionDestination $sessionDestination = null): array
    {
        return array_merge([
            'platform' => $this->platform(),
            'rtmp_host' => $this->destination?->rtmp_url ? parse_url((string) $this->destination->rtmp_url, PHP_URL_HOST) : null,
        ], $sessionDestination?->platform_meta ?? []);
    }

    protected function requireDestination(): StreamDestination
    {
        if (! $this->destination) {
            throw new \LogicException('Connector is not bound to a destination');
        }

        return $this->destination;
    }

    /** Build rtmp://host/app/KEY from a stored URL + key, validating scheme. */
    protected function buildRtmpUrl(?string $url, ?string $key): ?string
    {
        if (! $url || ! $key) {
            return null;
        }
        $url = rtrim(trim($url), '/');
        if (! preg_match('#^rtmps?://#i', $url)) {
            return null;
        }

        return $url.'/'.trim($key);
    }

    protected function validateRtmpUrl(?string $url): array
    {
        if (! $url) {
            return ['ok' => false, 'message' => 'RTMP URL is required'];
        }
        if (! preg_match('#^rtmps?://[A-Za-z0-9.\-]+(?::\d{1,5})?(?:/[A-Za-z0-9._\-]+)*/?$#', $url)) {
            return ['ok' => false, 'message' => 'RTMP URL must look like rtmp://host/app'];
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (! $host) {
            return ['ok' => false, 'message' => 'Invalid host'];
        }

        // A shape check alone made Test pass for a destination this server cannot even
        // reach, so the failure only turned up later, mid-broadcast.
        $reach = app(DestinationReachability::class)->check($url);

        return ['ok' => $reach['ok'], 'message' => $reach['message']];
    }
}
