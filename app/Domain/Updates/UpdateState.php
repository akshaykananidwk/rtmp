<?php

declare(strict_types=1);

namespace App\Domain\Updates;

/** Update engine state machine. */
final class UpdateState
{
    public const IDLE = 'idle';

    public const CHECKING = 'checking';

    public const BACKING_UP = 'backing_up';

    public const DOWNLOADING = 'downloading';

    public const VERIFYING = 'verifying';

    public const INSTALLING = 'installing';

    public const MIGRATING = 'migrating';

    public const HEALTH_CHECK = 'health_check';

    public const ACTIVATING = 'activating';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const ROLLING_BACK = 'rolling_back';

    public const ROLLED_BACK = 'rolled_back';

    public const IN_PROGRESS = [self::CHECKING, self::BACKING_UP, self::DOWNLOADING, self::VERIFYING, self::INSTALLING, self::MIGRATING, self::HEALTH_CHECK, self::ACTIVATING, self::ROLLING_BACK];

    public const TERMINAL = [self::COMPLETED, self::FAILED, self::ROLLED_BACK, self::IDLE];

    /** UI status label per state (matches spec: Checking, Downloading, Backing Up, Installing, Migrating, Testing, Completed, Failed, Rolled Back). */
    public static function label(string $state): string
    {
        return match ($state) {
            self::CHECKING => 'Checking',
            self::BACKING_UP => 'Backing Up',
            self::DOWNLOADING => 'Downloading',
            self::VERIFYING => 'Verifying',
            self::INSTALLING => 'Installing',
            self::MIGRATING => 'Migrating',
            self::HEALTH_CHECK => 'Testing',
            self::ACTIVATING => 'Activating',
            self::COMPLETED => 'Completed',
            self::FAILED => 'Failed',
            self::ROLLING_BACK => 'Rolling Back',
            self::ROLLED_BACK => 'Rolled Back',
            default => 'Idle',
        };
    }

    public static function allowed(string $from, string $to): bool
    {
        $map = [
            self::IDLE => [self::CHECKING],
            self::CHECKING => [self::BACKING_UP, self::FAILED],
            self::BACKING_UP => [self::DOWNLOADING, self::FAILED],
            self::DOWNLOADING => [self::VERIFYING, self::FAILED],
            self::VERIFYING => [self::INSTALLING, self::FAILED],
            self::INSTALLING => [self::MIGRATING, self::FAILED, self::ROLLING_BACK],
            self::MIGRATING => [self::HEALTH_CHECK, self::ROLLING_BACK],
            self::HEALTH_CHECK => [self::ACTIVATING, self::ROLLING_BACK],
            self::ACTIVATING => [self::COMPLETED, self::ROLLING_BACK],
            self::ROLLING_BACK => [self::ROLLED_BACK, self::FAILED],
        ];

        return in_array($to, $map[$from] ?? [], true);
    }
}
