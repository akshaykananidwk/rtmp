<?php

declare(strict_types=1);

namespace App\Domain\Health;

final class CheckResult
{
    public function __construct(
        public readonly string $service,
        public readonly string $status,   // pass | warn | fail
        public readonly string $message,
        public readonly array $details = [],
        public int $durationMs = 0,
    ) {}

    public static function pass(string $service, string $message = 'OK', array $details = []): self
    {
        return new self($service, 'pass', $message, $details);
    }

    public static function warn(string $service, string $message, array $details = []): self
    {
        return new self($service, 'warn', $message, $details);
    }

    public static function fail(string $service, string $message, array $details = []): self
    {
        return new self($service, 'fail', $message, $details);
    }

    public function ok(): bool
    {
        return $this->status !== 'fail';
    }

    public function toArray(): array
    {
        return ['service' => $this->service, 'status' => $this->status, 'message' => $this->message, 'details' => $this->details, 'duration_ms' => $this->durationMs];
    }
}
