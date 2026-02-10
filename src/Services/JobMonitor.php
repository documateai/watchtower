<?php

namespace Documateai\Watchtower\Services;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobRetryRequested;
use Documateai\Watchtower\Models\Job;
use Throwable;

class JobMonitor
{
    /**
     * Record a job that was just queued.
     */
    public function recordJobQueued(JobQueued $event): void
    {
        try {
            Job::create([
                'job_id' => $this->extractJobId($event),
                'queue' => $event->queue ?? 'default',
                'connection' => $event->connectionName,
                'payload' => $this->extractPayload($event->payload()),
                'status' => Job::STATUS_PENDING,
                'queued_at' => now(),
            ]);
        } catch (Throwable $e) {
            // Fail silently to not break the queue system
            report($e);
        }
    }

    /**
     * Record a job that started processing.
     */
    public function recordJobStarted(JobProcessing $event): void
    {
        try {
            $jobId = $this->extractJobId($event);

            Job::where('job_id', $jobId)->update([
                'status' => Job::STATUS_PROCESSING,
                'started_at' => now(),
                'worker_id' => $this->getCurrentWorkerId(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Record a job that completed successfully.
     */
    public function recordJobCompleted(JobProcessed $event): void
    {
        try {
            $jobId = $this->extractJobId($event);

            Job::where('job_id', $jobId)->update([
                'status' => Job::STATUS_COMPLETED,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Record a job that failed.
     */
    public function recordJobFailed(JobFailed $event): void
    {
        try {
            $jobId = $this->extractJobId($event);

            Job::where('job_id', $jobId)->update([
                'status' => Job::STATUS_FAILED,
                'completed_at' => now(),
                'exception' => $this->formatException($event->exception),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Record a job retry attempt.
     */
    public function recordJobRetrying(JobRetryRequested $event): void
    {
        try {
            $jobId = $this->extractJobId($event);

            Job::where('job_id', $jobId)->increment('attempts');
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Extract the job ID from the event.
     */
    protected function extractJobId($event): string
    {
        // For JobQueued events, the ID is directly on the event
        if ($event instanceof JobQueued) {
            // $event->id is the job ID for JobQueued
            if (isset($event->id)) {
                return (string) $event->id;
            }
        }

        // For JobProcessing/JobProcessed/JobFailed events, get from job object
        if (isset($event->job)) {
            if (method_exists($event->job, 'getJobId')) {
                $jobId = $event->job->getJobId();
                if ($jobId) {
                    return (string) $jobId;
                }
            }

            // Try uuid() method (Redis driver)
            if (method_exists($event->job, 'uuid')) {
                $uuid = $event->job->uuid();
                if ($uuid) {
                    return (string) $uuid;
                }
            }
        }

        // Try to get from payload
        if (method_exists($event, 'payload')) {
            $payload = $event->payload();
            if (isset($payload['id'])) {
                return (string) $payload['id'];
            }
            if (isset($payload['uuid'])) {
                return (string) $payload['uuid'];
            }
        }

        // Fallback: generate a unique ID
        return uniqid('watchtower_', true);
    }

    /**
     * Extract and format the job payload.
     */
    protected function extractPayload($payload): array
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        if (! is_array($payload)) {
            return [];
        }

        return [
            'displayName' => $payload['displayName'] ?? null,
            'job' => $payload['job'] ?? null,
            'maxTries' => $payload['maxTries'] ?? null,
            'maxExceptions' => $payload['maxExceptions'] ?? null,
            'timeout' => $payload['timeout'] ?? null,
            'data' => $payload['data'] ?? null,
        ];
    }

    /**
     * Format the exception for storage.
     */
    protected function formatException(Throwable $exception): string
    {
        return sprintf(
            "%s: %s in %s:%d\n\nStack trace:\n%s",
            get_class($exception),
            $exception->getMessage(),
            $exception->getFile(),
            $exception->getLine(),
            $exception->getTraceAsString()
        );
    }

    /**
     * Get the current worker ID if running in a worker context.
     */
    protected function getCurrentWorkerId(): ?int
    {
        // This will be set by the worker process
        // For now, return null (will be implemented in Phase 2)
        return null;
    }
}
