<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Generic critical alert (update failed, backup failed, disk full, engine offline ...). */
class SystemAlert
{
    use Dispatchable;

    public function __construct(
        public readonly string $type,      // update.failed | backup.failed | disk.critical | engine.offline | ...
        public readonly string $title,
        public readonly string $message,
        public readonly string $level = 'warning', // info | warning | critical
        public readonly array $meta = [],
    ) {}
}
