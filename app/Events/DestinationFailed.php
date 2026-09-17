<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\StreamSessionDestination;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DestinationFailed
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public readonly StreamSessionDestination $sessionDestination) {}
}
