<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Analytics\AnalyticsService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AnalyticsController extends Controller
{
    public function index(Request $request, AnalyticsService $analytics): View
    {
        $this->authorize('analytics.view');
        $days = (int) $request->query('days', 30);
        $days = in_array($days, [7, 30, 90], true) ? $days : 30;

        return view('admin.analytics', ['data' => $analytics->overview($days), 'days' => $days]);
    }
}
