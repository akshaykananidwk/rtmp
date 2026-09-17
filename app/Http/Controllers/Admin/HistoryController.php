<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StreamSession;
use Illuminate\View\View;

class HistoryController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', StreamSession::class);

        return view('admin.history.index', ['sessions' => StreamSession::with('endpoint')->withCount('destinations')->latest('started_at')->paginate(25)]);
    }

    public function show(StreamSession $session): View
    {
        $this->authorize('view', $session);
        $session->load(['endpoint', 'destinations.destination', 'recording']);

        return view('admin.history.show', ['session' => $session, 'logs' => $session->logs()->orderBy('id')->limit(500)->get()]);
    }
}
