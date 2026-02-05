<?php

namespace NathanPhelps\Watchtower\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use NathanPhelps\Watchtower\Models\Worker;
use Symfony\Component\Process\Process;

class WorkerManager
{
    /**
     * Active worker processes indexed by worker ID.
     */
    protected array $processes = [];

    /**
     * Start a new worker process.
     */
    public function startWorker(string $queue, array $options = []): string
    {
        $workerId = $this->generateWorkerId();
        $supervisor = $options['supervisor'] ?? 'default';

        // Build the command
        $command = $this->buildWorkerCommand($workerId, $queue, $options);

        // Create and start the process
        $process = new Process($command, base_path());
        $process->setTimeout(null);
        
        // Start the process and capture any immediate errors
        $process->start(function ($type, $buffer) use ($workerId) {
            // Log any output from the worker process for debugging
            if (Process::ERR === $type) {
                \Log::error("Watchtower worker [{$workerId}] stderr: {$buffer}");
            }
        });

        // Store process reference
        $this->processes[$workerId] = $process;

        // Wait a moment for process to actually start
        usleep(100000); // 100ms

        // Record worker in database
        Worker::create([
            'worker_id' => $workerId,
            'supervisor' => $supervisor,
            'queue' => $queue,
            'pid' => $process->getPid(),
            'status' => Worker::STATUS_RUNNING,
            'started_at' => now(),
            'last_heartbeat' => now(),
        ]);

        return $workerId;
    }

    /**
     * Stop a worker by sending a stop command.
     */
    public function stopWorker(string $workerId): void
    {
        $this->sendCommand($workerId, 'stop');

        Worker::where('worker_id', $workerId)->update([
            'status' => Worker::STATUS_STOPPED,
        ]);
    }

    /**
     * Pause a worker.
     */
    public function pauseWorker(string $workerId): void
    {
        $this->sendCommand($workerId, 'pause');

        Worker::where('worker_id', $workerId)->update([
            'status' => Worker::STATUS_PAUSED,
        ]);
    }

    /**
     * Resume a paused worker.
     */
    public function resumeWorker(string $workerId): void
    {
        $this->sendCommand($workerId, 'resume');

        Worker::where('worker_id', $workerId)->update([
            'status' => Worker::STATUS_RUNNING,
        ]);
    }

    /**
     * Get all running workers from the database.
     */
    public function getRunningWorkers(): Collection
    {
        return Worker::running()->get();
    }

    /**
     * Get all workers (any status) from the database.
     */
    public function getAllWorkers(): Collection
    {
        return Worker::orderBy('started_at', 'desc')->get();
    }

    /**
     * Check if a worker is running by verifying its process.
     */
    public function isWorkerRunning(string $workerId): bool
    {
        $worker = Worker::where('worker_id', $workerId)->first();

        if (! $worker || ! $worker->pid) {
            return false;
        }

        return $this->isProcessRunning($worker->pid);
    }

    /**
     * Clean up stale workers that are no longer running.
     */
    public function cleanupStaleWorkers(int $thresholdSeconds = 60): int
    {
        $staleWorkers = Worker::stale($thresholdSeconds)
            ->whereIn('status', [Worker::STATUS_RUNNING, Worker::STATUS_PAUSED])
            ->get();

        $count = 0;
        foreach ($staleWorkers as $worker) {
            if (! $this->isProcessRunning($worker->pid)) {
                $worker->update(['status' => Worker::STATUS_STOPPED]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Send a control command to a worker via Redis.
     */
    protected function sendCommand(string $workerId, string $command): void
    {
        $key = $this->getCommandKey($workerId);
        $connection = config('watchtower.redis_connection', 'default');

        Redis::connection($connection)->set($key, $command);
        // Set expiration to prevent stale commands
        Redis::connection($connection)->expire($key, 300);
    }

    /**
     * Get the Redis key for worker commands.
     */
    public function getCommandKey(string $workerId): string
    {
        return "watchtower:worker:{$workerId}:command";
    }

    /**
     * Generate a unique worker ID.
     */
    protected function generateWorkerId(): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Build the worker command array.
     */
    protected function buildWorkerCommand(string $workerId, string $queue, array $options): array
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');

        $command = [
            $php,
            $artisan,
            'watchtower:worker',
            $queue,
            '--worker-id='.$workerId,
        ];

        // Add optional parameters
        if (isset($options['tries'])) {
            $command[] = '--tries='.$options['tries'];
        }

        if (isset($options['timeout'])) {
            $command[] = '--timeout='.$options['timeout'];
        }

        if (isset($options['memory'])) {
            $command[] = '--memory='.$options['memory'];
        }

        if (isset($options['sleep'])) {
            $command[] = '--sleep='.$options['sleep'];
        }

        return $command;
    }

    /**
     * Check if a process is running by PID.
     */
    protected function isProcessRunning(?int $pid): bool
    {
        if (! $pid) {
            return false;
        }

        // Cross-platform process check
        if (PHP_OS_FAMILY === 'Windows') {
            $output = [];
            exec("tasklist /FI \"PID eq {$pid}\" 2>&1", $output);

            foreach ($output as $line) {
                if (str_contains($line, (string) $pid)) {
                    return true;
                }
            }

            return false;
        }

        // Unix-like systems
        return posix_kill($pid, 0);
    }

    /**
     * Terminate all workers immediately (for shutdown).
     */
    public function terminateAllWorkers(): void
    {
        $workers = Worker::whereIn('status', [Worker::STATUS_RUNNING, Worker::STATUS_PAUSED])->get();

        foreach ($workers as $worker) {
            $this->stopWorker($worker->worker_id);
        }
    }

    /**
     * Discover all queues in use by scanning Redis and database.
     * 
     * @return array<string> List of queue names
     */
    public function discoverQueues(): array
    {
        $queues = collect();
        $connection = config('queue.default', 'redis');

        // Method 1: Scan Redis for queue keys
        if (in_array($connection, ['redis', 'sync'])) {
            try {
                $redisConnection = config('watchtower.redis_connection', 'default');
                $prefix = config('database.redis.options.prefix', '');
                
                // Get all keys matching queue patterns
                $keys = Redis::connection($redisConnection)->keys('*queues:*');
                
                foreach ($keys as $key) {
                    // Remove prefix and extract queue name
                    $key = str_replace($prefix, '', $key);
                    if (preg_match('/queues:([^:]+)/', $key, $matches)) {
                        $queues->push($matches[1]);
                    }
                }
            } catch (\Throwable $e) {
                // Redis scan failed, continue with other methods
            }
        }

        // Method 2: Get queues from Watchtower job records
        try {
            $jobQueues = \NathanPhelps\Watchtower\Models\Job::distinct()
                ->pluck('queue')
                ->filter();
            $queues = $queues->merge($jobQueues);
        } catch (\Throwable $e) {
            // Job query failed, continue
        }

        // Method 3: Get queues from active workers
        try {
            $workerQueues = Worker::distinct()
                ->pluck('queue')
                ->filter();
            $queues = $queues->merge($workerQueues);
        } catch (\Throwable $e) {
            // Worker query failed, continue
        }

        // Always include 'default' queue
        $queues->push('default');

        return $queues->unique()->sort()->values()->toArray();
    }
}
