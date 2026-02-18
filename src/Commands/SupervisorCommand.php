<?php

namespace Documateai\Watchtower\Commands;

use Illuminate\Console\Command;
use Documateai\Watchtower\Contracts\CommandBusInterface;
use Documateai\Watchtower\Models\Job;
use Documateai\Watchtower\Models\Worker;
use Documateai\Watchtower\Services\WorkerManager;

class SupervisorCommand extends Command
{
    protected $signature = 'watchtower:supervisor
                            {--supervisor=default : The supervisor configuration to use}';

    protected $description = 'Start the Watchtower supervisor to manage queue workers';

    protected bool $running = true;

    protected CommandBusInterface $commandBus;

    /**
     * Strict column widths.
     */
    protected const QUEUE_WIDTH = 12;

    protected const WORKER_ID_WIDTH = 8;

    protected const BADGE_WIDTH = 7; // icon + space + 4-char text + space

    protected const DETAIL_WIDTH = 7;

    protected const TERM_WIDTH = 80;

    /**
     * Nerd Font / Powerline characters.
     */
    protected const ARROW = "\u{E0B0}";      // Powerline right arrow
    protected const ARROW_LEFT = "\u{E0B2}"; // Powerline left arrow (end cap)

    protected const ICON_EYE = "\u{F06E}";     // 󰁮 watchtower/monitoring
    protected const ICON_CHECK = "\u{F00C}";   //  done/success
    protected const ICON_TIMES = "\u{F00D}";   //  fail/error
    protected const ICON_PLAY = "\u{F04B}";    //  running
    protected const ICON_CLOCK = "\u{F017}";   //  waiting/pending
    protected const ICON_BOLT = "\u{F0E7}";    //  start/spawn
    protected const ICON_STOP = "\u{F04D}";    //  stop/shutdown
    protected const ICON_WARN = "\u{F071}";    //  warning
    protected const ICON_SWAP = "\u{F074}";    //  swap/replace
    protected const ICON_SKULL = "\u{F54C}";   //  dead process
    protected const ICON_GEAR = "\u{F013}";    //  init/config
    protected const ICON_INFO = "\u{F05A}";    //  info/summary

    /**
     * Truncate or pad a string to exact width.
     */
    protected function fit(string $str, int $width): string
    {
        if (mb_strlen($str) > $width) {
            return mb_substr($str, 0, $width - 1).'.';
        }

        return str_pad($str, $width);
    }

    /**
     * Build a styled badge: "icon TEXT " (7 chars).
     */
    protected function badge(string $icon, string $text, string $fg, string $bg): array
    {
        $plain = "{$icon} {$text} ";
        $styled = "<fg={$fg};bg={$bg}>{$icon} {$text} </>";

        return [$styled, $plain];
    }

    /**
     * Build the powerline prefix segments.
     * Returns [styled string, plain char count].
     */
    protected function powerline(string $queue): array
    {
        $timestamp = now()->format('H:i:s');
        $queueFit = $this->fit($queue, self::QUEUE_WIDTH);
        $a = self::ARROW;
        $eye = self::ICON_EYE;

        $segments = ''
            ."<fg=black;bg=cyan> {$eye} </>"
            ."<fg=cyan;bg=bright-blue>{$a}</>"
            ."<fg=bright-white;bg=bright-blue> {$timestamp} </>"
            ."<fg=bright-blue;bg=blue>{$a}</>"
            ."<fg=white;bg=blue> {$queueFit}</>"
            ."<fg=blue;bg=bright-black>{$a}</>";

        // " 󰁮 " (3) + arrow (1) + " HH:MM:SS " (10) + arrow (1) + " queue______" (13) + arrow (1) = 29
        $plainWidth = 3 + 1 + 10 + 1 + (1 + self::QUEUE_WIDTH) + 1;

        return [$segments, $plainWidth];
    }

    /**
     * Every line of output. Powerline prefix + action + dots + badge + detail.
     */
    protected function statusLine(string $queue, string $action, array $badgePair, string $detail = ''): void
    {
        [$prefix, $prefixWidth] = $this->powerline($queue);
        [$badge, $badgePlain] = $badgePair;

        $actionPlain = preg_replace('/<[^>]+>/', '', $action);

        // " " + action + " " + dots + " " + badge(7) + " " + detail(7) + " " + arrow(1)
        $overhead = 1 + 1 + 1 + mb_strlen($badgePlain) + 1 + self::DETAIL_WIDTH + 1 + 1;
        $actionDotsWidth = self::TERM_WIDTH - $prefixWidth - $overhead;

        $maxActionLen = $actionDotsWidth - 1;
        if (mb_strlen($actionPlain) > $maxActionLen) {
            $actionPlain = mb_substr($actionPlain, 0, max(1, $maxActionLen - 1)).'.';
            $action = $actionPlain;
        }

        $dotsNeeded = $actionDotsWidth - mb_strlen($actionPlain);
        $dots = str_repeat('.', max(1, $dotsNeeded));

        $detailCol = str_pad($detail, self::DETAIL_WIDTH, ' ', STR_PAD_LEFT);

        // Detail color
        $detailFg = 'gray';
        if (trim($detail) !== '') {
            $detailFg = str_contains($detail, 'Wkr') ? 'bright-blue' : 'bright-cyan';
        }

        // All sections get backgrounds for a continuous bar look
        // Action + dots: bright-black (dark gray) bg
        // Badge: its own colored bg (pops out)
        // Detail: black bg (slightly darker edge)
        $al = self::ARROW_LEFT;

        $this->line(
            "{$prefix}"
            ."<fg=bright-white;bg=bright-black> {$action} </>"
            ."<fg=gray;bg=bright-black>{$dots} </>"
            ."{$badge}"
            ."<fg={$detailFg};bg=black> {$detailCol} </>"
            ."<fg=black>{$al}</>"
        );
    }

    /**
     * Commonly used badges.
     */
    protected function badgeDone(): array
    {
        return $this->badge(self::ICON_CHECK, 'DONE', 'white', 'green');
    }

    protected function badgeFail(): array
    {
        return $this->badge(self::ICON_TIMES, 'FAIL', 'white', 'red');
    }

    protected function badgeRun(): array
    {
        return $this->badge(self::ICON_PLAY, 'RUN ', 'white', 'yellow');
    }

    protected function badgeWait(): array
    {
        return $this->badge(self::ICON_CLOCK, 'WAIT', 'white', 'blue');
    }

    protected function badgeStart(): array
    {
        return $this->badge(self::ICON_BOLT, 'NEW ', 'white', 'green');
    }

    protected function badgeStop(): array
    {
        return $this->badge(self::ICON_STOP, 'STOP', 'white', 'yellow');
    }

    protected function badgeWarn(): array
    {
        return $this->badge(self::ICON_WARN, 'WARN', 'white', 'yellow');
    }

    protected function badgeErr(): array
    {
        return $this->badge(self::ICON_WARN, 'ERR ', 'white', 'red');
    }

    protected function badgeSwap(): array
    {
        return $this->badge(self::ICON_SWAP, 'SWAP', 'white', 'yellow');
    }

    protected function badgeDead(): array
    {
        return $this->badge(self::ICON_SKULL, 'DEAD', 'white', 'red');
    }

    protected function badgeInit(): array
    {
        return $this->badge(self::ICON_GEAR, 'INIT', 'white', 'cyan');
    }

    protected function badgeInfo(): array
    {
        return $this->badge(self::ICON_INFO, 'INFO', 'white', 'cyan');
    }

    /**
     * Print a divider line.
     */
    protected function divider(): void
    {
        $a = self::ARROW;
        $al = self::ARROW_LEFT;
        $eye = self::ICON_EYE;
        $this->line(
            "<fg=black;bg=cyan> {$eye} </>"
            ."<fg=cyan;bg=bright-black>{$a}</>"
            .'<fg=gray;bg=bright-black>'.str_repeat('─', 75).'</>'
            ."<fg=bright-black>{$al}</>"
        );
    }

    public function handle(WorkerManager $workerManager, CommandBusInterface $commandBus): int
    {
        $this->commandBus = $commandBus;
        $supervisorName = $this->option('supervisor');
        $config = config("watchtower.supervisors.{$supervisorName}");

        if (! $config) {
            $this->statusLine('system', "Config '{$supervisorName}' missing", $this->badgeErr());

            return Command::FAILURE;
        }

        $this->printBanner($supervisorName, $config);

        while ($this->running) {
            try {
                if ($this->shouldTerminate()) {
                    $this->statusLine('system', 'Terminate signal', $this->badgeStop());
                    break;
                }

                $this->supervise($workerManager, $supervisorName, $config);
            } catch (\Throwable $e) {
                $msg = mb_substr($e->getMessage(), 0, 28);
                $this->statusLine('system', $msg, $this->badgeErr());
                report($e);
            }

            sleep(config('watchtower.worker_poll_interval', 3));
        }

        $this->statusLine('system', 'Shutdown complete', $this->badgeStop());
        $workerManager->terminateAllWorkers();

        return Command::SUCCESS;
    }

    protected function supervise(WorkerManager $workerManager, string $supervisorName, array $config): void
    {
        $queues = (array) $config['queue'];

        if ($queues === ['*'] || in_array('*', $queues, true)) {
            $queues = $workerManager->discoverQueues();
            if (empty($queues)) {
                $queues = ['default'];
            }
        }

        $staleCount = $workerManager->cleanupStaleWorkers();
        if ($staleCount > 0) {
            $this->statusLine('supervisor', "Pruned {$staleCount} stale", $this->badgeWarn());
        }

        $runningWorkers = Worker::forSupervisor($supervisorName)
            ->whereIn('status', [Worker::STATUS_RUNNING, Worker::STATUS_PAUSED])
            ->get();

        $currentCount = $runningWorkers->count();
        $minProcesses = $config['min_processes'];

        if ($currentCount < $minProcesses) {
            $toStart = $minProcesses - $currentCount;

            for ($i = 0; $i < $toStart; $i++) {
                $queue = $this->getNextQueue($queues, $i, $config['balance'] ?? 'simple');
                $workerId = $workerManager->startWorker($queue, [
                    'supervisor' => $supervisorName,
                    'tries' => $config['tries'] ?? 3,
                    'timeout' => $config['timeout'] ?? 60,
                    'memory' => $config['memory'] ?? 128,
                    'sleep' => $config['sleep'] ?? 3,
                ]);

                $shortId = substr($workerId, 0, self::WORKER_ID_WIDTH);
                $this->statusLine('supervisor', "Wkr {$shortId} spawned", $this->badgeStart(), '+1 Wkr');
            }
        }

        foreach ($runningWorkers as $worker) {
            if (! $workerManager->isWorkerRunning($worker->worker_id)) {
                $worker->update(['status' => Worker::STATUS_STOPPED]);
                $shortId = substr($worker->worker_id, 0, self::WORKER_ID_WIDTH);

                if ($runningWorkers->count() <= $minProcesses) {
                    $queue = $worker->queue;
                    $newWorkerId = $workerManager->startWorker($queue, [
                        'supervisor' => $supervisorName,
                        'tries' => $config['tries'] ?? 3,
                        'timeout' => $config['timeout'] ?? 60,
                        'memory' => $config['memory'] ?? 128,
                        'sleep' => $config['sleep'] ?? 3,
                    ]);

                    $newShort = substr($newWorkerId, 0, self::WORKER_ID_WIDTH);
                    $this->statusLine('supervisor', "{$newShort} repl {$shortId}", $this->badgeSwap());
                } else {
                    $this->statusLine('supervisor', "Wkr {$shortId} lost", $this->badgeDead(), '-1 Wkr');
                }
            }
        }

        $this->outputStatus($supervisorName, $runningWorkers->count());
    }

    protected function getNextQueue(array $queues, int $index, string $balance = 'simple'): string
    {
        if ($balance === 'simple') {
            return implode(',', $queues);
        }

        return $queues[$index % count($queues)];
    }

    protected function printBanner(string $supervisorName, array $config): void
    {
        $queues = implode(', ', (array) $config['queue']);
        $balance = $config['balance'] ?? 'simple';
        $workers = "{$config['min_processes']}-{$config['max_processes']} ({$balance})";

        $this->newLine();
        $this->divider();
        $this->statusLine('supervisor', $supervisorName, $this->badgeInit());
        $this->statusLine('queues', $queues, $this->badgeInit());
        $this->statusLine('workers', $workers, $this->badgeInit());
        $this->divider();
        $this->newLine();
    }

    protected function outputStatus(string $supervisor, int $workerCount): void
    {
        static $lastJobId = 0;
        static $lastSummaryAt = 0;
        static $processedSinceLastSummary = 0;
        static $failedSinceLastSummary = 0;

        $newJobs = Job::where('id', '>', $lastJobId)
            ->orderBy('id')
            ->limit(50)
            ->get();

        foreach ($newJobs as $job) {
            $name = $job->getJobClass() ?? $job->job_id;
            $queue = $job->queue ?? 'default';

            if (str_contains($name, '\\')) {
                $name = class_basename($name);
            }

            $badgePair = match ($job->status) {
                Job::STATUS_COMPLETED  => $this->badgeDone(),
                Job::STATUS_FAILED     => $this->badgeFail(),
                Job::STATUS_PROCESSING => $this->badgeRun(),
                Job::STATUS_PENDING    => $this->badgeWait(),
                default                => $this->badge(self::ICON_INFO, str_pad(strtoupper($job->status), 4), 'white', 'gray'),
            };

            $detail = '';
            if ($job->status === Job::STATUS_COMPLETED && $job->getDuration() !== null) {
                $ms = round($job->getDuration() * 1000);
                $detail = $ms >= 1000 ? round($ms / 1000, 1).'s' : "{$ms}ms";
            }

            $this->statusLine($queue, $name, $badgePair, $detail);

            if ($job->status === Job::STATUS_COMPLETED) {
                $processedSinceLastSummary++;
            } elseif ($job->status === Job::STATUS_FAILED) {
                $failedSinceLastSummary++;

                if ($job->exception) {
                    $exceptionLine = strtok($job->exception, "\n");
                    $this->statusLine($queue, $exceptionLine, $this->badgeFail());
                }
            }

            $lastJobId = max($lastJobId, $job->id);
        }

        if (time() - $lastSummaryAt >= 30) {
            $pending = Job::where('status', Job::STATUS_PENDING)->count();
            $processing = Job::where('status', Job::STATUS_PROCESSING)->count();

            $check = self::ICON_CHECK;
            $times = self::ICON_TIMES;
            $play = self::ICON_PLAY;
            $clock = self::ICON_CLOCK;

            $parts = [];
            $parts[] = "<fg=green>{$check} {$processedSinceLastSummary}</>";
            $parts[] = "<fg=red>{$times} {$failedSinceLastSummary}</>";
            $parts[] = "<fg=yellow>{$play} {$processing}</>";
            $parts[] = "<fg=blue>{$clock} {$pending}</>";

            $summary = 'Jobs: '.implode(' / ', $parts);
            $this->statusLine('summary', $summary, $this->badgeInfo(), "{$workerCount} Wkr");

            $processedSinceLastSummary = 0;
            $failedSinceLastSummary = 0;
            $lastSummaryAt = time();
        }
    }

    protected function shouldTerminate(): bool
    {
        $terminateAt = $this->commandBus->get('watchtower:terminate');

        if ($terminateAt) {
            $this->commandBus->forget('watchtower:terminate');

            return true;
        }

        return false;
    }
}
