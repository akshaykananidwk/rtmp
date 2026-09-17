<?php

declare(strict_types=1);

namespace App\Domain\Destinations;

use App\Domain\Destinations\Connectors\CustomRtmpConnector;
use App\Domain\Destinations\Connectors\FacebookConnector;
use App\Domain\Destinations\Connectors\InstagramConnector;
use App\Domain\Destinations\Connectors\LinkedInConnector;
use App\Domain\Destinations\Connectors\TwitchConnector;
use App\Domain\Destinations\Connectors\YouTubeConnector;
use App\Models\StreamDestination;
use Illuminate\Contracts\Container\Container;

/**
 * Plugin registry: platforms register a connector class here.
 * Adding a platform = adding one class + one registry line. No core changes.
 */
class ConnectorRegistry
{
    /** @var array<string, class-string<StreamingDestinationInterface>> */
    private array $connectors = [];

    public function __construct(private readonly Container $container)
    {
        $this->register(CustomRtmpConnector::class);
        $this->register(YouTubeConnector::class);
        $this->register(FacebookConnector::class);
        $this->register(TwitchConnector::class);
        $this->register(LinkedInConnector::class);
        $this->register(InstagramConnector::class);
    }

    /** @param class-string<StreamingDestinationInterface> $class */
    public function register(string $class): void
    {
        $instance = $this->container->make($class);
        $this->connectors[$instance->platform()] = $class;
    }

    public function has(string $platform): bool
    {
        return isset($this->connectors[$platform]);
    }

    public function make(string $platform): StreamingDestinationInterface
    {
        if (! $this->has($platform)) {
            throw new \InvalidArgumentException("Unsupported platform: $platform");
        }

        return $this->container->make($this->connectors[$platform]);
    }

    public function for(StreamDestination $destination): StreamingDestinationInterface
    {
        return $this->make($destination->platform)->connect($destination);
    }

    /** @return array<string, PlatformDefinition> */
    public function definitions(): array
    {
        $out = [];
        foreach (array_keys($this->connectors) as $platform) {
            $out[$platform] = $this->make($platform)->definition();
        }

        return $out;
    }

    /** @return string[] */
    public function platforms(): array
    {
        return array_keys($this->connectors);
    }
}
