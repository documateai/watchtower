<?php

namespace Documateai\Watchtower\Commands;

use Illuminate\Console\Command;
use Documateai\Watchtower\Contracts\CommandBusInterface;
use Documateai\Watchtower\Models\Job;
use Documateai\Watchtower\Models\Worker;
use Documateai\Watchtower\Services\WorkerManager;

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

        $this->printBanner($supervisorName, $config);

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
     * Print the startup banner.
     */
    protected function printBanner(string $supervisorName, array $config): void
    {
        $queues = implode(', ', (array) $config['queue']);
        $balance = $config['balance'] ?? 'simple';

        $this->newLine();
        $this->line('  <fg=cyan;options=bold>WATCHTOWER</> <fg=gray>v'.config('watchtower.version', '3').'</>');
        $this->line('  <fg=gray>'.str_repeat('-', 44).'</>');
        $this->line("  <fg=white>Supervisor</>  <fg=cyan>{$supervisorName}</>");
        $this->line("  <fg=white>Queues</>      <fg=cyan>{$queues}</>");
        $this->line("  <fg=white>Workers</>     <fg=cyan>{$config['min_processes']}-{$config['max_processes']}</> <fg=gray>({$balance})</>");
        $this->line('  <fg=gray>'.str_repeat('-', 44).'</>');
        $this->newLine();
    }

    /**
     * Output current status — streams job activity with color-coded statuses.
     * Worker count is only reported when it changes.
     */
    protected function outputStatus(string $supervisor, int $workerCount): void
    {
        static $lastWorkerCount = null;
        static $lastJobId = 0;
        static $lastSummaryAt = 0;
        static $processedSinceLastSummary = 0;
        static $failedSinceLastSummary = 0;

        $now = now();
        $timestamp = $now->format('H:i:s');

        // Report worker count changes
        if ($lastWorkerCount !== null && $workerCount !== $lastWorkerCount) {
            $delta = $workerCount - $lastWorkerCount;
            $arrow = $delta > 0 ? '<fg=green>▲ +'.$delta.'</>' : '<fg=red>▼ '.$delta.'</>';
            $this->line("  <fg=gray>{$timestamp}</>  {$arrow}  <fg=white;options=bold>Workers: {$workerCount}</>");
        }
        $lastWorkerCount = $workerCount;

        // Stream new jobs since last poll
        $newJobs = Job::where('id', '>', $lastJobId)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($newJobs as $job) {
            $name = $job->getJobClass() ?? $job->job_id;
            $queue = $job->queue;

            // Shorten long class names to just the class basename
            if (str_contains($name, '\\')) {
                $name = class_basename($name);
            }

            $statusBadge = match ($job->status) {
                Job::STATUS_COMPLETED => '<fg=black;bg=green> DONE </>',
                Job::STATUS_FAILED    => '<fg=white;bg=red> FAIL </>',
                Job::STATUS_PROCESSING => '<fg=black;bg=yellow> RUN  </>',
                Job::STATUS_PENDING   => '<fg=white;bg=blue> WAIT </>',
                default               => '<fg=white;bg=gray> '.strtoupper(str_pad($job->status, 4)).' </>',
            };

            $duration = '';
            if ($job->status === Job::STATUS_COMPLETED && $job->getDuration() !== null) {
                $ms = round($job->getDuration() * 1000);
                $duration = $ms >= 1000
                    ? ' <fg=gray>'.round($ms / 1000, 1).'s</>'
                    : " <fg=gray>{$ms}ms</>";
            }

            $this->line("  <fg=gray>{$timestamp}</>  {$statusBadge}  <fg=white>{$name}</> <fg=gray>on</> <fg=cyan>{$queue}</>{$duration}");

            if ($job->status === Job::STATUS_COMPLETED) {
                $processedSinceLastSummary++;
            } elseif ($job->status === Job::STATUS_FAILED) {
                $failedSinceLastSummary++;

                // Show exception preview for failed jobs
                if ($job->exception) {
                    $exceptionLine = strtok($job->exception, "\n");
                    if (strlen($exceptionLine) > 80) {
                        $exceptionLine = substr($exceptionLine, 0, 80).'...';
                    }
                    $this->line("           <fg=red>└ {$exceptionLine}</>");
                }
            }

            $lastJobId = max($lastJobId, $job->id);
        }

        // Periodic summary every 30 seconds
        if (time() - $lastSummaryAt >= 30) {
            $pending = Job::where('status', Job::STATUS_PENDING)->count();
            $processing = Job::where('status', Job::STATUS_PROCESSING)->count();

            $parts = [];
            if ($processedSinceLastSummary > 0) {
                $parts[] = "<fg=green>{$processedSinceLastSummary} done</>";
            }
            if ($failedSinceLastSummary > 0) {
                $parts[] = "<fg=red>{$failedSinceLastSummary} failed</>";
            }
            if ($processing > 0) {
                $parts[] = "<fg=yellow>{$processing} running</>";
            }
            if ($pending > 0) {
                $parts[] = "<fg=blue>{$pending} pending</>";
            }

            if (! empty($parts)) {
                $summary = implode(' <fg=gray>|</> ', $parts);
                $this->line("  <fg=gray>{$timestamp}</>  <fg=gray>---</> {$summary} <fg=gray>| {$workerCount} worker(s)</>");
            }

            $processedSinceLastSummary = 0;
            $failedSinceLastSummary = 0;
            $lastSummaryAt = time();
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
