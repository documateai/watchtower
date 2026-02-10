<?php

namespace Documateai\Watchtower\Commands;

use Illuminate\Console\Command;
use Documateai\Watchtower\Contracts\CommandBusInterface;
use Documateai\Watchtower\Models\Worker;

class RestartCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'watchtower:restart
                            {--queue= : Only restart workers on specific queue}
                            {--force : Force immediate restart without waiting for current job}';

    /**
     * The console command description.
     */
    protected $description = 'Gracefully restart all Watchtower workers for zero-downtime deployments';

    /**
     * Execute the console command.
     */
    public function handle(CommandBusInterface $commandBus): int
    {
        $queue = $this->option('queue');
        $force = $this->option('force');

        $this->info('Signaling workers to restart...');

        // Get all running workers
        $query = Worker::where('status', Worker::STATUS_RUNNING);

        if ($queue) {
            $query->where('queue', $queue);
        }

        $workers = $query->get();

        if ($workers->isEmpty()) {
            $this->warn('No running workers found.');

            return Command::SUCCESS;
        }

        $command = $force ? 'terminate' : 'restart';
        $restartedCount = 0;

        foreach ($workers as $worker) {
            $key = "watchtower:worker:{$worker->worker_id}:command";
            $commandBus->put($key, $command);

            $this->line("  → Sent {$command} signal to worker [{$worker->worker_id}] on queue [{$worker->queue}]");
            $restartedCount++;
        }

        $this->newLine();

        if ($force) {
            $this->info("Sent terminate signal to {$restartedCount} worker(s).");
            $this->comment('Workers will stop immediately after current job completes.');
        } else {
            $this->info("Sent restart signal to {$restartedCount} worker(s).");
            $this->comment('Workers will finish their current job, then restart with fresh code.');
        }

        // Also set a global restart timestamp for new workers
        $commandBus->put('watchtower:restart_at', (string) now()->timestamp);

        $this->newLine();
        $this->info('Restart signal sent successfully.');
        $this->comment('Run "php artisan watchtower:status" to monitor worker status.');

        return Command::SUCCESS;
    }
}
