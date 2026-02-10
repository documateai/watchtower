<?php

namespace Documateai\Watchtower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Documateai\Watchtower\Services\MetricsCollector;

class MetricsController extends Controller
{
    public function __construct(
        protected MetricsCollector $metricsCollector
    ) {}

    /**
     * Display metrics dashboard.
     */
    public function index(): View|JsonResponse
    {
        $data = [
            'stats' => $this->metricsCollector->getStats(),
            'hourlyThroughput' => $this->metricsCollector->getHourlyThroughput(),
            'queueDepths' => $this->metricsCollector->getQueueDepths(),
            'averageDurations' => $this->metricsCollector->getAverageDurations(),
        ];

        if (request()->wantsJson()) {
            return response()->json($data);
        }

        return view('watchtower::metrics', [
            'initialData' => $data,
        ]);
    }
}
