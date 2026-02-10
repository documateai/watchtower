<?php

namespace Documateai\Watchtower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Documateai\Watchtower\Models\Job;
use Documateai\Watchtower\Services\WorkerManager;

class WorkersController extends Controller
{
    public function __construct(
        protected WorkerManager $workerManager
    ) {}

    /**
     * Display worker management page.
     */
    public function index(): View|JsonResponse
    {
        $workers = $this->workerManager->getAllWorkers();
        $queues = Job::select('queue')->distinct()->pluck('queue')->push('default')->unique()->values();

        if (request()->wantsJson()) {
            return response()->json([
                'workers' => $workers,
                'queues' => $queues,
            ]);
        }

        return view('watchtower::workers', [
            'initialData' => [
                'workers' => $workers,
                'queues' => $queues,
            ],
        ]);
    }

    /**
     * Start a new worker.
     */
    public function start(Request $request): JsonResponse|RedirectResponse
    {
        $request->validate([
            'queue' => 'required|string',
        ]);

        $workerId = $this->workerManager->startWorker($request->input('queue'));

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Worker started',
                'worker_id' => $workerId,
            ]);
        }

        return back()->with('success', 'Worker started');
    }

    /**
     * Stop a worker.
     */
    public function stop(string $workerId): JsonResponse|RedirectResponse
    {
        $this->workerManager->stopWorker($workerId);

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Stop command sent']);
        }

        return back()->with('success', 'Worker stop command sent');
    }

    /**
     * Pause a worker.
     */
    public function pause(string $workerId): JsonResponse|RedirectResponse
    {
        $this->workerManager->pauseWorker($workerId);

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Pause command sent']);
        }

        return back()->with('success', 'Worker pause command sent');
    }

    /**
     * Resume a worker.
     */
    public function resume(string $workerId): JsonResponse|RedirectResponse
    {
        $this->workerManager->resumeWorker($workerId);

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Resume command sent']);
        }

        return back()->with('success', 'Worker resume command sent');
    }
}
