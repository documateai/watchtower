<?php

namespace NathanPhelps\Watchtower\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Worker extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'watchtower_workers';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'worker_id',
        'supervisor',
        'queue',
        'pid',
        'status',
        'started_at',
        'last_heartbeat',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'started_at' => 'datetime',
        'last_heartbeat' => 'datetime',
        'pid' => 'integer',
    ];

    /**
     * Worker status constants.
     */
    const STATUS_RUNNING = 'running';

    const STATUS_PAUSED = 'paused';

    const STATUS_STOPPED = 'stopped';

    /**
     * Get the jobs processed by this worker.
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(Job::class);
    }

    /**
     * Check if the worker is running.
     */
    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    /**
     * Check if the worker is paused.
     */
    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    /**
     * Check if the worker is stopped.
     */
    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED;
    }

    /**
     * Check if the worker is healthy (received heartbeat recently).
     */
    public function isHealthy(int $thresholdSeconds = 30): bool
    {
        if (! $this->last_heartbeat) {
            return false;
        }

        return $this->last_heartbeat->diffInSeconds(now()) <= $thresholdSeconds;
    }

    /**
     * Get the uptime of the worker in seconds.
     */
    public function getUptime(): int
    {
        return $this->started_at->diffInSeconds(now(), absolute: false);
    }

    /**
     * Get the number of jobs processed by this worker.
     */
    public function getJobsProcessedCount(): int
    {
        return $this->jobs()->where('status', Job::STATUS_COMPLETED)->count();
    }

    /**
     * Get the number of jobs failed by this worker.
     */
    public function getJobsFailedCount(): int
    {
        return $this->jobs()->where('status', Job::STATUS_FAILED)->count();
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by supervisor.
     */
    public function scopeForSupervisor($query, string $supervisor)
    {
        return $query->where('supervisor', $supervisor);
    }

    /**
     * Scope to get running workers.
     */
    public function scopeRunning($query)
    {
        return $query->withStatus(self::STATUS_RUNNING);
    }

    /**
     * Scope to get stale workers (no recent heartbeat).
     */
    public function scopeStale($query, int $thresholdSeconds = 30)
    {
        return $query->where(function ($q) use ($thresholdSeconds) {
            $q->whereNull('last_heartbeat')
                ->orWhere('last_heartbeat', '<', now()->subSeconds($thresholdSeconds));
        });
    }
}
