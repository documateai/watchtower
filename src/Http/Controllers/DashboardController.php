<?php

namespace Documateai\Watchtower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Documateai\Watchtower\Models\Job;
use Documateai\Watchtower\Services\MetricsCollector;
use Documateai\Watchtower\Services\WorkerManager;

class DashboardController extends Controller
{
    public function __construct(
        protected WorkerManager $workerManager,
        protected MetricsCollector $metricsCollector
    ) {}

    /**
     * Display the Watchtower dashboard.
     */
    public function index(): View
    {
        return view('watchtower::dashboard', [
            'initialData' => [
                'stats' => $this->metricsCollector->getStats(),
                'recentJobs' => Job::recent(20)->get(),
                'workers' => $this->workerManager->getRunningWorkers(),
                'pollInterval' => config('watchtower.dashboard_poll_interval', 3000),
            ],
        ]);
    }

    /**
     * Get polling data for real-time updates.
     */
    public function poll(Request $request): JsonResponse
    {
        return response()->json([
            'stats' => $this->metricsCollector->getStats(),
            'recentJobs' => Job::recent(20)->get(),
            'workers' => $this->workerManager->getRunningWorkers(),
        ]);
    }
}
