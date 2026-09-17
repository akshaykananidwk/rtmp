<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Domain\Health\HealthService;
use App\Http\Controllers\Controller;
use App\Models\HealthCheck;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class HealthController extends Controller
{
    public function __construct(private readonly HealthService $health) {}

    public function index(): View
    {
        $this->authorize('health.view');

        return view('admin.health.index', ['latest' => $this->health->latest(), 'checks' => $this->health->checks()]);
    }

    public function run(): RedirectResponse
    {
        $this->authorize('health.view');
        $run = $this->health->run('manual');

        return back()->with($run['critical_ok'] ? 'status' : 'error', 'Health check finished: '.$run['summary']['pass'].' pass, '.$run['summary']['warn'].' warn, '.$run['summary']['fail'].' fail.');
    }

    public function show(string $service): View
    {
        $this->authorize('health.view');
        abort_unless(preg_match('/^[a-z_]{2,32}$/', $service), 404);

        return view('admin.health.show', ['service' => $service, 'label' => $this->health->labelFor($service), 'history' => HealthCheck::where('service', $service)->latest('id')->limit(50)->get()]);
    }
}
