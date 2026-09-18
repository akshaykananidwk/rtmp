<?php

declare(strict_types=1);

namespace App\Domain\Destinations;

final class PlatformDefinition
{
    /**
     * @param  array<int, array{name:string,label:string,type:string,required?:bool,help?:string,options?:array}>  $fields
     * @param  string[]  $setupSteps  What the operator does, in order, to make this platform work
     */
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $connectionMethod, // rtmp | oauth
        public readonly bool $oauthSupported,
        public readonly bool $officialApi,
        public readonly string $support, // supported | partial | not_supported_by_api
        public readonly string $description,
        public readonly array $fields = [],
        public readonly ?string $defaultRtmpUrl = null,
        public readonly string $icon = '📡',
        public readonly ?string $docsUrl = null,
        public readonly array $setupSteps = [],
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
