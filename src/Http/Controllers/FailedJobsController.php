<?php

namespace Documateai\Watchtower\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\View\View;
use Documateai\Watchtower\Models\Job;

class FailedJobsController extends Controller
{
    /**
     * Display failed jobs listing.
     */
    public function index(): View|JsonResponse
    {
        $jobs = Job::failed()->orderBy('completed_at', 'desc')->paginate(50);

        if (request()->wantsJson()) {
            return response()->json(['jobs' => $jobs]);
        }

        return view('watchtower::failed-jobs', [
            'initialData' => ['jobs' => $jobs],
        ]);
    }

    /**
     * Retry a failed job.
     */
    public function retry(int $id): JsonResponse|RedirectResponse
    {
        $job = Job::failed()->findOrFail($id);

        // Reset job status for retry
        $job->update([
            'status' => Job::STATUS_PENDING,
            'started_at' => null,
            'completed_at' => null,
            'exception' => null,
        ]);

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Job queued for retry']);
        }

        return back()->with('success', 'Job queued for retry');
    }

    /**
     * Delete a failed job record.
     */
    public function destroy(int $id): JsonResponse|RedirectResponse
    {
        Job::failed()->findOrFail($id)->delete();

        if (request()->wantsJson()) {
            return response()->json(['message' => 'Job deleted']);
        }

        return back()->with('success', 'Job record deleted');
    }
}
