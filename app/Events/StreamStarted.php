<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StreamSession;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StreamStarted
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly StreamSession $session) {}
}
