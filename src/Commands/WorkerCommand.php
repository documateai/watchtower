<?php

namespace Documateai\Watchtower\Commands;

use Illuminate\Console\Command;
use Illuminate\Queue\WorkerOptions;
use Documateai\Watchtower\Contracts\CommandBusInterface;
use Documateai\Watchtower\Models\Worker as WorkerModel;

class WorkerCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'watchtower:worker
                            {queue=default : The queue to process}
                            {--worker-id= : The unique worker ID}
                            {--tries=3 : Number of times to attempt a job before logging it failed}
                            {--timeout=60 : The number of seconds a child process can run}
                            {--memory=128 : The memory limit in megabytes}
                            {--sleep=3 : Number of seconds to sleep when no job is available}
                            {--rest=0 : Number of seconds to rest between jobs}';

    /**
     * The console command description.
     */
    protected $description = 'Start a Watchtower queue worker with polling-based control';

    /**
     * The worker ID for this instance.
     */
    protected string $workerId;

    /**
     * Whether the worker is paused.
     */
    protected bool $paused = false;

    /**
     * Whether the worker should stop.
     */
    protected bool $shouldStop = false;

    protected CommandBusInterface $commandBus;

    /**
     * Execute the console command.
     */
    public function handle(CommandBusInterface $commandBus): int
    {
        $this->commandBus = $commandBus;
        $this->workerId = $this->option('worker-id') ?? $this->generateWorkerId();
        $connection = config('watchtower.supervisors.default.connection', 'redis');
        $queueName = $this->argument('queue');

        $this->info("Starting Watchtower worker [{$this->workerId}] on queue [{$queueName}]");

        // Register this worker if not already registered
        $this->registerWorker($queueName);

        // Get the queue worker from Laravel's container (properly bound with all dependencies)
        $worker = $this->laravel['queue.worker'];

        // Build worker options
        $options = $this->buildWorkerOptions();

        // Register event listeners for console output
        $this->registerJobEventListeners();

        // Main worker loop with polling-based control
        while (! $this->shouldStop) {
            // Check for control commands
            $this->checkForCommands();

            if ($this->paused) {
                $this->waitWhilePaused();

                continue;
            }

            // Send heartbeat
            $this->sendHeartbeat();

            // Process the next job
            try {
                $worker->runNextJob(
                    $connection,
                    $queueName,
                    $options
                );
            } catch (\Throwable $e) {
                $this->error("Error processing job: {$e->getMessage()}");
                report($e);
            }

            // Check memory limit
            if ($this->memoryExceeded($options->memory)) {
                $this->info('Memory limit exceeded, stopping worker...');
                $this->shouldStop = true;
            }
        }

        $this->gracefulShutdown();

        return Command::SUCCESS;
    }

    /**
     * Register this worker in the database.
     * Note: Worker record is created by WorkerManager, we just update it here.
     */
    protected function registerWorker(string $queue): void
    {
        $worker = WorkerModel::where('worker_id', $this->workerId)->first();

        if ($worker) {
            // Update existing record created by WorkerManager
            $worker->update([
                'pid' => getmypid(),
                'status' => WorkerModel::STATUS_RUNNING,
                'started_at' => now(),
                'last_heartbeat' => now(),
            ]);
        } else {
            // Create new record if worker was started manually (not via supervisor)
            WorkerModel::create([
                'worker_id' => $this->workerId,
                'supervisor' => 'manual',
                'queue' => $queue,
                'pid' => getmypid(),
                'status' => WorkerModel::STATUS_RUNNING,
                'started_at' => now(),
                'last_heartbeat' => now(),
            ]);
        }
    }

    /**
     * Check for control commands.
     */
    protected function checkForCommands(): void
    {
        $key = $this->getCommandKey();
        $command = $this->commandBus->get($key);

        if (! $command) {
            return;
        }

        // Clear the command
        $this->commandBus->forget($key);

        switch ($command) {
            case 'stop':
                $this->info('Received stop command');
                $this->shouldStop = true;
                break;

            case 'pause':
                $this->info('Received pause command');
                $this->paused = true;
                WorkerModel::where('worker_id', $this->workerId)->update([
                    'status' => WorkerModel::STATUS_PAUSED,
                ]);
                break;

            case 'resume':
                $this->info('Received resume command');
                $this->paused = false;
                WorkerModel::where('worker_id', $this->workerId)->update([
                    'status' => WorkerModel::STATUS_RUNNING,
                ]);
                break;

            case 'restart':
                $this->info('Received restart command - will stop after current job');
                $this->shouldStop = true;
                // Mark as restarting so supervisor knows to restart it
                WorkerModel::where('worker_id', $this->workerId)->update([
                    'status' => 'restarting',
                ]);
                break;

            case 'terminate':
                $this->info('Received terminate command - stopping immediately');
                $this->shouldStop = true;
                break;
        }
    }

    /**
     * Wait while the worker is paused.
     */
    protected function waitWhilePaused(): void
    {
        $pollInterval = config('watchtower.worker_poll_interval', 3);

        while ($this->paused && ! $this->shouldStop) {
            sleep($pollInterval);
            $this->checkForCommands();
            $this->sendHeartbeat();
        }
    }

    /**
     * Send a heartbeat to indicate the worker is alive.
     */
    protected function sendHeartbeat(): void
    {
        WorkerModel::where('worker_id', $this->workerId)->update([
            'last_heartbeat' => now(),
        ]);
    }

    /**
     * Get the Redis command key for this worker.
     */
    protected function getCommandKey(): string
    {
        return "watchtower:worker:{$this->workerId}:command";
    }

    /**
     * Build the worker options from command arguments.
     */
    protected function buildWorkerOptions(): WorkerOptions
    {
        return new WorkerOptions(
            name: 'watchtower',
            backoff: 0,
            memory: (int) $this->option('memory'),
            timeout: (int) $this->option('timeout'),
            sleep: (int) $this->option('sleep'),
            maxTries: (int) $this->option('tries'),
            force: false,
            stopWhenEmpty: false,
            maxJobs: 0,
            maxTime: 0,
            rest: (int) $this->option('rest'),
        );
    }

    /**
     * Generate a unique worker ID.
     */
    protected function generateWorkerId(): string
    {
        return \Illuminate\Support\Str::uuid()->toString();
    }

    /**
     * Check if memory has been exceeded.
     */
    protected function memoryExceeded(int $memoryLimit): bool
    {
        return (memory_get_usage(true) / 1024 / 1024) >= $memoryLimit;
    }

    /**
     * Perform a graceful shutdown.
     */
    protected function gracefulShutdown(): void
    {
        $this->info("Shutting down worker [{$this->workerId}]");

        WorkerModel::where('worker_id', $this->workerId)->update([
            'status' => WorkerModel::STATUS_STOPPED,
        ]);

        // Clear any pending commands
        $this->commandBus->forget($this->getCommandKey());
    }

    /**
     * Register event listeners for console output.
     */
    protected function registerJobEventListeners(): void
    {
        $command = $this;

        // Listen for job processing started
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessing::class,
            function ($event) use ($command) {
                $jobName = $event->job->resolveName();
                $command->line("<fg=yellow>Processing:</> {$jobName}");
            }
        );

        // Listen for job completed
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobProcessed::class,
            function ($event) use ($command) {
                $jobName = $event->job->resolveName();
                $command->info("✓ Completed: {$jobName}");
            }
        );

        // Listen for job failed
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Queue\Events\JobFailed::class,
            function ($event) use ($command) {
                $jobName = $event->job->resolveName();
                $message = $event->exception->getMessage();
                $command->error("✗ Failed: {$jobName} - {$message}");
            }
        );
    }
}
