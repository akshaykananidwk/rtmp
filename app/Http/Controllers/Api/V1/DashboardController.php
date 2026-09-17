<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Analytics\AnalyticsService;
use App\Http\Controllers\Admin\DashboardController as Web;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('dashboard.view');

        return response()->json(['data' => Web::payload()]);
    }

    public function status(): JsonResponse
    {
        $this->authorize('dashboard.view');
        $p = Web::payload();

        return response()->json(['data' => ['live' => $p['live'], 'session' => $p['session'], 'summary' => $p['summary'], 'destinations' => $p['destinations']]]);
    }

    public function analytics(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $this->authorize('analytics.view');
        $days = (int) $request->query('days', 30);

        return response()->json(['data' => $analytics->overview(in_array($days, [7, 30, 90], true) ? $days : 30)]);
    }
}
