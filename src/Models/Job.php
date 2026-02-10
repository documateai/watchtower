<?php

namespace Documateai\Watchtower\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Job extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'watchtower_jobs';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'job_id',
        'queue',
        'connection',
        'payload',
        'status',
        'worker_id',
        'attempts',
        'exception',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'payload' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'attempts' => 'integer',
        'worker_id' => 'integer',
    ];

    /**
     * Job status constants.
     */
    const STATUS_PENDING = 'pending';

    const STATUS_PROCESSING = 'processing';

    const STATUS_COMPLETED = 'completed';

    const STATUS_FAILED = 'failed';

    /**
     * Get the worker that processed this job.
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    /**
     * Check if the job is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Check if the job failed.
     */
    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    /**
     * Check if the job is pending.
     */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Check if the job is currently processing.
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Get the duration of the job in seconds.
     */
    public function getDuration(): ?float
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return $this->completed_at->diffInSeconds($this->started_at, absolute: true);
    }

    /**
     * Get the job class name from the payload.
     */
    public function getJobClass(): ?string
    {
        if (! isset($this->payload['displayName'])) {
            return null;
        }

        return $this->payload['displayName'];
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter by queue.
     */
    public function scopeOnQueue($query, string $queue)
    {
        return $query->where('queue', $queue);
    }

    /**
     * Scope to get recent jobs.
     */
    public function scopeRecent($query, int $limit = 100)
    {
        return $query->orderBy('queued_at', 'desc')->limit($limit);
    }

    /**
     * Scope to get failed jobs.
     */
    public function scopeFailed($query)
    {
        return $query->withStatus(self::STATUS_FAILED);
    }

    /**
     * Scope to get completed jobs.
     */
    public function scopeCompleted($query)
    {
        return $query->withStatus(self::STATUS_COMPLETED);
    }
}
