<?php

namespace Documateai\Watchtower\Commands;

use Illuminate\Console\Command;
use Documateai\Watchtower\Models\Job;

class PruneJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'watchtower:prune
                            {--completed= : Retention period for completed jobs in days}
                            {--failed= : Retention period for failed jobs in days}
                            {--all : Prune all jobs regardless of status}';

    /**
     * The console command description.
     */
    protected $description = 'Prune old job records from the watchtower_jobs table';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->option('all')) {
            return $this->pruneAll();
        }

        $completedRetention = $this->option('completed')
            ?? config('watchtower.retention.completed', 7);

        $failedRetention = $this->option('failed')
            ?? config('watchtower.retention.failed', 30);

        $completedDeleted = 0;
        $failedDeleted = 0;

        // Prune completed jobs
        if ($completedRetention !== null) {
            $completedDeleted = Job::withStatus(Job::STATUS_COMPLETED)
                ->where('completed_at', '<', now()->subDays((int) $completedRetention))
                ->delete();
        }

        // Prune failed jobs
        if ($failedRetention !== null) {
            $failedDeleted = Job::withStatus(Job::STATUS_FAILED)
                ->where('completed_at', '<', now()->subDays((int) $failedRetention))
                ->delete();
        }

        $totalDeleted = $completedDeleted + $failedDeleted;

        $this->info("Pruned {$completedDeleted} completed job(s) older than {$completedRetention} day(s).");
        $this->info("Pruned {$failedDeleted} failed job(s) older than {$failedRetention} day(s).");
        $this->info("Total: {$totalDeleted} job record(s) deleted.");

        return Command::SUCCESS;
    }

    /**
     * Prune all job records.
     */
    protected function pruneAll(): int
    {
        if (! $this->confirm('This will delete ALL job records. Are you sure?')) {
            $this->info('Operation cancelled.');

            return Command::SUCCESS;
        }

        $deleted = Job::truncate();

        $this->info('All job records have been deleted.');

        return Command::SUCCESS;
    }
}
