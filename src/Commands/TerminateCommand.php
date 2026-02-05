<?php

namespace NathanPhelps\Watchtower\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use NathanPhelps\Watchtower\Models\Worker;

class TerminateCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'watchtower:terminate
                            {--wait : Wait for all workers to terminate}';

    /**
     * The console command description.
     */
    protected $description = 'Terminate all Watchtower workers and supervisor processes';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Broadcasting terminate signal to all Watchtower processes...');

        $connection = config('watchtower.redis_connection', 'default');

        // Set a global terminate flag that supervisor checks
        Redis::connection($connection)->set('watchtower:terminate', now()->timestamp);

        // Send terminate signal to all running workers
        $workers = Worker::where('status', Worker::STATUS_RUNNING)->get();
        
        foreach ($workers as $worker) {
            $key = "watchtower:worker:{$worker->worker_id}:command";
            Redis::connection($connection)->set($key, 'terminate');
            Redis::connection($connection)->expire($key, 300);
            $this->line("  → Sent terminate to worker [{$worker->worker_id}]");
        }

        $this->newLine();
        $this->info("Sent terminate signal to {$workers->count()} worker(s).");

        if ($this->option('wait')) {
            $this->info('Waiting for workers to terminate...');
            
            $maxWait = 60; // seconds
            $waited = 0;
            
            while ($waited < $maxWait) {
                $running = Worker::where('status', Worker::STATUS_RUNNING)->count();
                
                if ($running === 0) {
                    $this->info('All workers have terminated.');
                    break;
                }
                
                $this->line("  Waiting... ({$running} worker(s) still running)");
                sleep(2);
                $waited += 2;
            }
            
            if ($waited >= $maxWait) {
                $this->warn('Timeout waiting for workers to terminate.');
            }
        }

        $this->newLine();
        $this->comment('Supervisor will exit after current jobs complete.');
        $this->comment('Run "php artisan watchtower:supervisor" to start again.');

        return Command::SUCCESS;
    }
}
