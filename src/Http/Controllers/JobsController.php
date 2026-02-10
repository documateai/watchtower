<?php

namespace NathanPhelps\Watchtower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use NathanPhelps\Watchtower\Models\Job;

class JobsController extends Controller
{
    /**
     * Display listing of jobs.
     */
    public function index(Request $request): View|JsonResponse
    {
        $query = Job::query();

        if ($request->filled('status')) {
            $query->withStatus($request->input('status'));
        }

        if ($request->filled('queue')) {
            $query->onQueue($request->input('queue'));
        }

        if ($request->filled('search')) {
            $query->where('payload', 'like', '%'.$request->input('search').'%');
        }

        $jobs = $query->orderBy('queued_at', 'desc')->paginate(50);
        $queues = Job::select('queue')->distinct()->pluck('queue');

        if ($request->wantsJson()) {
            return response()->json([
                'jobs' => $jobs,
                'queues' => $queues,
                'filters' => $request->only(['status', 'queue', 'search']),
            ]);
        }

        return view('watchtower::jobs', [
            'initialData' => [
                'jobs' => $jobs,
                'queues' => $queues,
                'filters' => $request->only(['status', 'queue', 'search']),
            ],
        ]);
    }

    /**
     * Display job details.
     */
    public function show(int $id): View|JsonResponse
    {
        $job = Job::with('worker')->findOrFail($id);

        if (request()->wantsJson()) {
            return response()->json(['job' => $job]);
        }

        return view('watchtower::job-detail', [
            'initialData' => ['job' => $job],
        ]);
    }
}
