<?php

namespace NathanPhelps\Watchtower\Commands;

use Illuminate\Console\Command;
use NathanPhelps\Watchtower\Contracts\CommandBusInterface;
use NathanPhelps\Watchtower\Models\Worker;
use NathanPhelps\Watchtower\Services\WorkerManager;

class SupervisorCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'watchtower:supervisor
                            {--supervisor=default : The supervisor configuration to use}';

    /**
     * The console command description.
     */
    protected $description = 'Start the Watchtower supervisor to manage queue workers';

    /**
     * Whether the supervisor should continue running.
     */
    protected bool $running = true;

    protected CommandBusInterface $commandBus;

    /**
     * Execute the console command.
     */
    public function handle(WorkerManager $workerManager, CommandBusInterface $commandBus): int
    {
        $this->commandBus = $commandBus;
        $supervisorName = $this->option('supervisor');
        $config = config("watchtower.supervisors.{$supervisorName}");

        if (! $config) {
            $this->error("Supervisor configuration '{$supervisorName}' not found.");

            return Command::FAILURE;
        }

        $this->info("Starting Watchtower supervisor [{$supervisorName}]");
        $this->info('Queue: '.implode(', ', (array) $config['queue']));
        $this->info("Min processes: {$config['min_processes']}, Max processes: {$config['max_processes']}");

        // Main supervisor loop
        while ($this->running) {
            try {
                // Check for terminate signal
                if ($this->shouldTerminate()) {
                    $this->info('Received terminate signal');
                    break;
                }

                $this->supervise($workerManager, $supervisorName, $config);
            } catch (\Throwable $e) {
                $this->error("Supervisor error: {$e->getMessage()}");
                report($e);
            }

            // Poll interval
            sleep(config('watchtower.worker_poll_interval', 3));
        }

        $this->info('Supervisor shutting down...');
        $workerManager->terminateAllWorkers();

        return Command::SUCCESS;
    }

    /**
     * Main supervision logic.
     */
    protected function supervise(WorkerManager $workerManager, string $supervisorName, array $config): void
    {
        $queues = (array) $config['queue'];

        // Auto-discover queues if set to '*'
        if ($queues === ['*'] || in_array('*', $queues, true)) {
            $queues = $workerManager->discoverQueues();
            if (empty($queues)) {
                $queues = ['default'];
            }
        }

        // Clean up stale workers
        $staleCount = $workerManager->cleanupStaleWorkers();
        if ($staleCount > 0) {
            $this->warn("Cleaned up {$staleCount} stale worker(s)");
        }

        // Get current running workers for this supervisor
        $runningWorkers = Worker::forSupervisor($supervisorName)
            ->whereIn('status', [Worker::STATUS_RUNNING, Worker::STATUS_PAUSED])
            ->get();

        $currentCount = $runningWorkers->count();
        $minProcesses = $config['min_processes'];
        $maxProcesses = $config['max_processes'];

        // Scale up if below minimum
        if ($currentCount < $minProcesses) {
            $toStart = $minProcesses - $currentCount;
            $this->info("Starting {$toStart} worker(s) to meet minimum");

            for ($i = 0; $i < $toStart; $i++) {
                $queue = $this->getNextQueue($queues, $i, $config['balance'] ?? 'simple');
                $workerId = $workerManager->startWorker($queue, [
                    'supervisor' => $supervisorName,
                    'tries' => $config['tries'] ?? 3,
                    'timeout' => $config['timeout'] ?? 60,
                    'memory' => $config['memory'] ?? 128,
                    'sleep' => $config['sleep'] ?? 3,
                ]);
                $this->info("Started worker [{$workerId}] on queue [{$queue}]");
            }
        }

        // Verify workers are still alive
        foreach ($runningWorkers as $worker) {
            if (! $workerManager->isWorkerRunning($worker->worker_id)) {
                $this->warn("Worker [{$worker->worker_id}] is no longer running, marking as stopped");
                $worker->update(['status' => Worker::STATUS_STOPPED]);

                // Restart if we're below minimum
                if ($runningWorkers->count() <= $minProcesses) {
                    // Use the same queue assignment as the failed worker
                    $queue = $worker->queue;
                    $newWorkerId = $workerManager->startWorker($queue, [
                        'supervisor' => $supervisorName,
                        'tries' => $config['tries'] ?? 3,
                        'timeout' => $config['timeout'] ?? 60,
                        'memory' => $config['memory'] ?? 128,
                        'sleep' => $config['sleep'] ?? 3,
                    ]);
                    $this->info("Restarted worker [{$newWorkerId}] on queue [{$queue}]");
                }
            }
        }

        // Output status periodically
        $this->outputStatus($supervisorName, $runningWorkers->count());
    }

    /**
     * Get the next queue to assign a worker to based on balance strategy.
     *
     * @param  array  $queues  Available queues
     * @param  int  $index  Worker index for round-robin
     * @param  string  $balance  Balance strategy ('simple' or 'auto')
     * @return string Queue name(s) - single queue for 'auto', comma-separated for 'simple'
     */
    protected function getNextQueue(array $queues, int $index, string $balance = 'simple'): string
    {
        if ($balance === 'simple') {
            // All workers process all queues (comma-separated for Laravel queue worker)
            return implode(',', $queues);
        }

        // 'auto' mode: round-robin assignment (each worker gets one queue)
        return $queues[$index % count($queues)];
    }

    /**
     * Output current status.
     */
    protected function outputStatus(string $supervisor, int $workerCount): void
    {
        static $lastOutput = 0;

        // Only output every 30 seconds
        if (time() - $lastOutput >= 30) {
            $this->info("[{$supervisor}] Active workers: {$workerCount}");
            $lastOutput = time();
        }
    }

    /**
     * Check if the supervisor should terminate.
     */
    protected function shouldTerminate(): bool
    {
        $terminateAt = $this->commandBus->get('watchtower:terminate');

        if ($terminateAt) {
            // Clear the terminate flag so next start works normally
            $this->commandBus->forget('watchtower:terminate');

            return true;
        }

        return false;
    }
}
