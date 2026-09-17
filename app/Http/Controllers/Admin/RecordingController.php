<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Recording\RecordingService;
use App\Domain\Storage\DiskMonitor;
use App\Http\Controllers\Controller;
use App\Models\Recording;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordingController extends Controller
{
    public function __construct(private readonly RecordingService $recordings) {}

    public function index(DiskMonitor $disk): View
    {
        $this->authorize('viewAny', Recording::class);

        return view('admin.recordings', [
            'recordings' => Recording::with('session')->latest('started_at')->paginate(20),
            'totalSize' => $this->recordings->totalSize(),
            'disk' => $disk->usage(),
        ]);
    }

    public function download(Recording $recording): StreamedResponse
    {
        $this->authorize('download', $recording);

        return $this->recordings->download($recording);
    }

    public function destroy(Recording $recording): RedirectResponse
    {
        $this->authorize('delete', $recording);
        $this->recordings->delete($recording);

        return back()->with('status', 'Recording deleted.');
    }
}
