<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Audit\AuditLogger;
use App\Domain\Backups\BackupService;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Support\SecretMasker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(private readonly BackupService $backups) {}

    public function index(): View
    {
        $this->authorize('viewAny', Backup::class);

        return view('admin.backups', ['backups' => Backup::latest()->paginate(20), 'totalSize' => $this->backups->totalSize()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Backup::class);
        $data = $request->validate(['type' => ['required', 'in:full,files,database'], 'encrypt' => ['nullable', 'boolean']]);
        try {
            $backup = $this->backups->create($data['type'], 'manual', $request->user()->id, $request->boolean('encrypt') ?: null);
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'Backup '.$backup->id.' created ('.number_format($backup->size_bytes / 1048576, 1).' MB).');
    }

    public function download(Backup $backup, string $part): BinaryFileResponse
    {
        $this->authorize('download', $backup);
        $file = $part === 'database' ? $backup->db_location : ($part === 'files' ? $backup->location : null);
        abort_unless($file && is_file($file), 404);
        app(AuditLogger::class)->log('backup.downloaded', $backup, ['part' => $part]);

        return response()->download($file, basename($file), ['X-Content-Type-Options' => 'nosniff']);
    }

    public function verify(Backup $backup): RedirectResponse
    {
        $this->authorize('view', $backup);
        $ok = $this->backups->verify($backup);

        return back()->with($ok ? 'status' : 'error', $ok ? 'Backup integrity verified.' : 'Backup checksum mismatch or file missing!');
    }

    public function restore(Request $request, Backup $backup): RedirectResponse
    {
        $this->authorize('restore', $backup);
        $data = $request->validate(['part' => ['required', 'in:database,files'], 'confirm' => ['required', 'in:RESTORE']]);
        try {
            if ($data['part'] === 'database') {
                $this->backups->restoreDatabase($backup, $request->user()->id);
            } else {
                $this->backups->restoreFiles($backup, null, $request->user()->id);
            }
        } catch (\Throwable $e) {
            return back()->with('error', 'Restore failed: '.SecretMasker::maskString($e->getMessage()));
        }

        return back()->with('status', ucfirst($data['part']).' restored from backup.');
    }

    public function destroy(Request $request, Backup $backup): RedirectResponse
    {
        $this->authorize('delete', $backup);
        $this->backups->delete($backup, $request->user()->id);

        return back()->with('status', 'Backup deleted.');
    }
}
