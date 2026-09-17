<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ErrorLog;
use App\Models\StreamDestinationLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LogController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('logs.view');
        $level = $request->query('level');

        return view('admin.logs.stream', ['logs' => StreamDestinationLog::with('destination')->when(in_array($level, ['info', 'warning', 'error'], true), fn ($q) => $q->where('level', $level))->latest('id')->paginate(50)->withQueryString(), 'level' => $level]);
    }

    public function audit(Request $request): View
    {
        $this->authorize('logs.view');
        $tenantId = $request->user()->tenant_id;
        $user = $request->user();

        return view('admin.logs.audit', ['logs' => ActivityLog::with('user')->when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $tenantId))->latest('id')->paginate(50)]);
    }

    public function errors(Request $request): View
    {
        $this->authorize('logs.view');
        $user = $request->user();

        return view('admin.logs.errors', ['errorLogs' => ErrorLog::when(! $user->isSuperAdmin(), fn ($q) => $q->where('tenant_id', $user->tenant_id))->latest('id')->paginate(30)]);
    }

    public function error(Request $request, ErrorLog $error): View
    {
        $this->authorize('logs.view');
        abort_unless($request->user()->isSuperAdmin() || $error->tenant_id === $request->user()->tenant_id, 403);

        return view('admin.logs.error', ['error' => $error]);
    }
}
