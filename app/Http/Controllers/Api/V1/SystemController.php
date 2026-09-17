<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Health\HealthService;
use App\Domain\Updates\UpdateChecker;
use App\Domain\Updates\UpdateManager;
use App\Http\Controllers\Controller;
use App\Jobs\RunUpdateJob;
use App\Models\Update;
use App\Support\SecretMasker;
use App\Support\Version;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SystemController extends Controller
{
    /** Public liveness: no details leaked. */
    public function health(): JsonResponse
    {
        try {
            DB::select('select 1');
            $db = true;
        } catch (\Throwable) {
            $db = false;
        }

        return response()->json(['status' => $db ? 'ok' : 'degraded', 'version' => Version::current()], $db ? 200 : 503);
    }

    public function fullHealth(HealthService $health): JsonResponse
    {
        $this->authorize('health.view');
        $run = $health->run('api', false);

        return response()->json(['ok' => $run['ok'], 'critical_ok' => $run['critical_ok'], 'summary' => $run['summary'], 'results' => array_map(fn ($r) => $r->toArray(), $run['results'])]);
    }

    public function updateCheck(UpdateChecker $checker): JsonResponse
    {
        $this->authorize('viewAny', Update::class);
        try {
            return response()->json(['data' => collect($checker->check())->except('changes')]);
        } catch (\Throwable $e) {
            return response()->json(['message' => SecretMasker::maskString($e->getMessage())], 502);
        }
    }

    public function updateInstall(Request $request, UpdateManager $manager): JsonResponse
    {
        $this->authorize('manage', Update::class);
        abort_unless($request->user()->tokenCan('manage'), 403, 'Token lacks the manage ability.');
        try {
            $update = $manager->begin($request->user()->id, $request->input('sha'));
        } catch (\Throwable $e) {
            return response()->json(['message' => SecretMasker::maskString($e->getMessage())], 409);
        }
        RunUpdateJob::dispatch($update->id);

        return response()->json(['message' => 'Update started.', 'update_id' => $update->id, 'status_url' => route('admin.updates.status', $update)], 202);
    }
}
