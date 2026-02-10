<?php

namespace NathanPhelps\Watchtower\Commands;

use Illuminate\Console\Command;
use NathanPhelps\Watchtower\Models\Worker;
use NathanPhelps\Watchtower\Services\MetricsCollector;

class StatusCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'watchtower:status';

    /**
     * The console command description.
     */
    protected $description = 'Show the current status of Watchtower workers and jobs';

    /**
     * Execute the console command.
     */
    public function handle(MetricsCollector $metrics): int
    {
        $this->newLine();
        $this->info('Watchtower Status');
        $this->info('=================');

        $this->displayWorkers($metrics);
        $this->displayJobs($metrics);
        $this->displayQueueDepths($metrics);

        return Command::SUCCESS;
    }

    /**
     * Display worker status section.
     */
    protected function displayWorkers(MetricsCollector $metrics): void
    {
        $this->newLine();
        $stats = $metrics->getWorkerStats();

        $this->line(sprintf(
            '  <fg=white>Workers</>  Total: <fg=white>%d</>  Running: <fg=green>%d</>  Paused: <fg=yellow>%d</>  Stopped: <fg=red>%d</>',
            $stats['total'],
            $stats['running'],
            $stats['paused'],
            $stats['stopped'],
        ));

        $workers = Worker::whereIn('status', [
            Worker::STATUS_RUNNING,
            Worker::STATUS_PAUSED,
            Worker::STATUS_STOPPED,
        ])->orderByRaw("CASE status WHEN 'running' THEN 1 WHEN 'paused' THEN 2 ELSE 3 END")
            ->get();

        if ($workers->isEmpty()) {
            $this->newLine();
            $this->warn('  No workers registered. Start the supervisor with: php artisan watchtower:supervisor');

            return;
        }

        $this->newLine();
        $this->table(
            ['ID', 'Queue', 'Status', 'Uptime'],
            $workers->map(fn (Worker $worker) => [
                $worker->worker_id,
                $worker->queue,
                $this->formatStatus($worker->status),
                $this->formatUptime($worker->getUptime()),
            ])->toArray(),
        );
    }

    /**
     * Display job statistics section.
     */
    protected function displayJobs(MetricsCollector $metrics): void
    {
        $stats = $metrics->getStats();

        $this->newLine();
        $this->line(sprintf(
            '  <fg=white>Jobs</> (last hour)  Pending: <fg=white>%d</>  Processing: <fg=white>%d</>  Completed: <fg=green>%d</>  Failed: <fg=red>%d</>',
            $stats['pending'],
            $stats['processing'],
            $stats['completed_last_hour'],
            $stats['failed_last_hour'],
        ));
    }

    /**
     * Display queue depth section.
     */
    protected function displayQueueDepths(MetricsCollector $metrics): void
    {
        $depths = $metrics->getQueueDepths();

        if ($depths->isEmpty()) {
            return;
        }

        $this->newLine();
        $this->line('  <fg=white>Queue Depths</>');

        $maxNameLength = $depths->keys()->map(fn ($name) => strlen($name))->max();

        $depths->sortDesc()->each(function ($count, $queue) use ($maxNameLength) {
            $dots = str_repeat('.', $maxNameLength - strlen($queue) + 4);
            $color = $count > 0 ? 'yellow' : 'green';
            $this->line(sprintf('    %s %s <fg=%s>%d</>', $queue, $dots, $color, $count));
        });

        $this->newLine();
    }

    /**
     * Format worker status with color.
     */
    protected function formatStatus(string $status): string
    {
        return match ($status) {
            Worker::STATUS_RUNNING => '<fg=green>running</>',
            Worker::STATUS_PAUSED => '<fg=yellow>paused</>',
            Worker::STATUS_STOPPED => '<fg=red>stopped</>',
            default => $status,
        };
    }

    /**
     * Format seconds into human-readable uptime.
     */
    protected function formatUptime(int $seconds): string
    {
        $seconds = abs($seconds);

        if ($seconds < 60) {
            return "{$seconds}s";
        }

        if ($seconds < 3600) {
            $m = intdiv($seconds, 60);
            $s = $seconds % 60;

            return "{$m}m {$s}s";
        }

        if ($seconds < 86400) {
            $h = intdiv($seconds, 3600);
            $m = intdiv($seconds % 3600, 60);

            return "{$h}h {$m}m";
        }

        $d = intdiv($seconds, 86400);
        $h = intdiv($seconds % 86400, 3600);

        return "{$d}d {$h}h";
    }
}
