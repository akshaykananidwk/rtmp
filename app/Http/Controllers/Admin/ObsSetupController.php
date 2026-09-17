<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Streaming\StreamKeyService;
use App\Http\Controllers\Controller;
use App\Models\StreamEndpoint;
use Illuminate\View\View;

class ObsSetupController extends Controller
{
    public function show(StreamKeyService $keys, ?StreamEndpoint $streamKey = null): View
    {
        $streamKey ??= StreamEndpoint::where('is_enabled', true)->orderBy('created_at')->first();
        if ($streamKey) {
            $this->authorize('view', $streamKey);
        } else {
            $this->authorize('viewAny', StreamEndpoint::class);
        }

        return view('admin.obs-setup', [
            'endpoint' => $streamKey,
            'endpoints' => StreamEndpoint::orderBy('name')->get(),
            'rtmpUrl' => $keys->publicRtmpUrl(),
            'canReveal' => $streamKey ? auth()->user()->can('reveal', $streamKey) : false,
        ]);
    }
}
