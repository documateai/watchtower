# Watchtower API Reference

Complete reference for services, commands, and HTTP endpoints.

---

## Artisan Commands

### `watchtower:supervisor`

Start the supervisor to manage queue workers.

```bash
php artisan watchtower:supervisor [--supervisor=default]
```

**Options:**

| Option | Description |
|--------|-------------|
| `--supervisor` | Supervisor configuration name (default: `default`) |

**Behavior:**

- Spawns workers to meet `min_processes`
- Monitors worker health via heartbeats
- Restarts failed workers automatically
- Cleans up stale worker records

---

### `watchtower:worker`

Start a single queue worker.

```bash
php artisan watchtower:worker [queue] [options]
```

**Arguments:**

| Argument | Description |
|----------|-------------|
| `queue` | Queue name to process (default: `default`) |

**Options:**

| Option | Default | Description |
|--------|---------|-------------|
| `--worker-id` | auto | Unique worker identifier |
| `--tries` | 3 | Max job attempts |
| `--timeout` | 60 | Job timeout (seconds) |
| `--memory` | 128 | Memory limit (MB) |
| `--sleep` | 3 | Sleep when queue empty |
| `--rest` | 0 | Rest between jobs |

---

### `watchtower:prune`

Remove old job records.

```bash
php artisan watchtower:prune [options]
```

**Options:**

| Option | Description |
|--------|-------------|
| `--completed` | Retention days for completed jobs |
| `--failed` | Retention days for failed jobs |
| `--all` | Delete ALL job records (with confirmation) |

**Examples:**

```bash
# Use config defaults
php artisan watchtower:prune

# Custom retention
php artisan watchtower:prune --completed=3 --failed=7

# Clear everything
php artisan watchtower:prune --all
```

---

## Services

### CommandBusInterface

**Interface:** `Documateai\Watchtower\Contracts\CommandBusInterface`

Abstraction for the worker control channel. Registered as a singleton, resolved based on `watchtower.command_bus` config.

**Implementations:**

| Class | Driver | Description |
|-------|--------|-------------|
| `RedisCommandBus` | `redis` | Uses `Redis::connection()` for command storage |
| `DatabaseCommandBus` | `database` | Uses `watchtower_commands` table with TTL expiration |

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `put(string $key, string $value, int $ttl = 300)` | `void` | Store a command with TTL |
| `get(string $key)` | `?string` | Retrieve a command (null if missing/expired) |
| `forget(string $key)` | `void` | Delete a command |

---

### WorkerManager

**Class:** `Documateai\Watchtower\Services\WorkerManager`

Manages worker process lifecycle. Uses `CommandBusInterface` for sending control commands.

```php
use Documateai\Watchtower\Services\WorkerManager;

$manager = app(WorkerManager::class);
```

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `startWorker(string $queue, array $options = [])` | `string` | Start worker, returns worker ID |
| `stopWorker(string $workerId)` | `void` | Send stop command |
| `pauseWorker(string $workerId)` | `void` | Send pause command |
| `resumeWorker(string $workerId)` | `void` | Send resume command |
| `getRunningWorkers()` | `Collection` | Get active workers |
| `getAllWorkers()` | `Collection` | Get all workers |
| `isWorkerRunning(string $workerId)` | `bool` | Check if process alive |
| `cleanupStaleWorkers(int $threshold = 60)` | `int` | Remove stale records |
| `terminateAllWorkers()` | `void` | Stop all workers |

**Example:**

```php
// Start a worker
$workerId = $manager->startWorker('emails', [
    'tries' => 5,
    'timeout' => 120,
]);

// Pause and resume
$manager->pauseWorker($workerId);
$manager->resumeWorker($workerId);

// Stop
$manager->stopWorker($workerId);
```

---

### MetricsCollector

**Class:** `Documateai\Watchtower\Services\MetricsCollector`

Aggregates job and worker statistics.

```php
use Documateai\Watchtower\Services\MetricsCollector;

$metrics = app(MetricsCollector::class);
```

**Methods:**

| Method | Return | Description |
|--------|--------|-------------|
| `getStats()` | `array` | Overall job counts |
| `getHourlyThroughput()` | `Collection` | Jobs per hour (24h) |
| `getQueueDepths()` | `Collection` | Pending jobs per queue |
| `getAverageDurations()` | `Collection` | Avg job duration per queue |
| `getRecentThroughput()` | `Collection` | Jobs per minute (10m) |
| `getWorkerStats()` | `array` | Worker counts by status |

**Stats Array:**

```php
[
    'total_jobs' => 1234,
    'pending' => 12,
    'processing' => 3,
    'completed' => 1100,
    'failed' => 119,
    'completed_last_hour' => 45,
    'failed_last_hour' => 2,
    'active_workers' => 4,
    'paused_workers' => 1,
]
```

---

### JobMonitor

**Class:** `Documateai\Watchtower\Services\JobMonitor`

Records queue events to database. Automatically registered - typically not called directly.

---

## Models

### Job

**Class:** `Documateai\Watchtower\Models\Job`

```php
use Documateai\Watchtower\Models\Job;

// Scopes
Job::withStatus('failed')->get();
Job::onQueue('emails')->get();
Job::recent(50)->get();
Job::failed()->get();
Job::completed()->get();

// Instance methods
$job->isCompleted();
$job->isFailed();
$job->isPending();
$job->isProcessing();
$job->getDuration();     // seconds
$job->getJobClass();     // e.g., "App\Jobs\SendEmail"

// Relationships
$job->worker;
```

**Attributes:**

| Attribute | Type | Description |
|-----------|------|-------------|
| `job_id` | string | Laravel job UUID |
| `queue` | string | Queue name |
| `connection` | string | Queue connection |
| `payload` | array | Job data (cast) |
| `status` | string | pending/processing/completed/failed |
| `worker_id` | int | FK to worker |
| `attempts` | int | Retry count |
| `exception` | string | Error trace |
| `queued_at` | Carbon | When queued |
| `started_at` | Carbon | When started |
| `completed_at` | Carbon | When finished |

---

### Worker

**Class:** `Documateai\Watchtower\Models\Worker`

```php
use Documateai\Watchtower\Models\Worker;

// Scopes
Worker::running()->get();
Worker::withStatus('paused')->get();
Worker::forSupervisor('default')->get();
Worker::stale(60)->get();  // No heartbeat in 60s

// Instance methods
$worker->isRunning();
$worker->isPaused();
$worker->isStopped();
$worker->isHealthy(30);   // Heartbeat within 30s
$worker->getUptime();     // seconds
$worker->getJobsProcessedCount();
$worker->getJobsFailedCount();

// Relationships
$worker->jobs;
```

---

## HTTP Endpoints

All routes prefixed with configured path (default: `/watchtower`).

### Dashboard

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/` | Dashboard page |
| `GET` | `/api/poll` | Polling data (JSON) |

**Poll Response:**

```json
{
    "stats": { ... },
    "recentJobs": [ ... ],
    "workers": [ ... ]
}
```

### Jobs

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/jobs` | Job list (paginated) |
| `GET` | `/jobs/{id}` | Job detail |

**Query Parameters:**

| Param | Description |
|-------|-------------|
| `status` | Filter by status |
| `queue` | Filter by queue |
| `search` | Search job class |
| `page` | Pagination |

### Failed Jobs

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/failed` | Failed jobs list |
| `POST` | `/failed/{id}/retry` | Retry job |
| `DELETE` | `/failed/{id}` | Delete job record |

### Workers

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/workers` | Worker list |
| `POST` | `/workers/start` | Start new worker |
| `POST` | `/workers/{id}/stop` | Stop worker |
| `POST` | `/workers/{id}/pause` | Pause worker |
| `POST` | `/workers/{id}/resume` | Resume worker |

**Start Worker Body:**

```json
{
    "queue": "default"
}
```

### Metrics

| Method | Route | Description |
|--------|-------|-------------|
| `GET` | `/metrics` | Metrics dashboard |

---

## Events

Watchtower listens to these Laravel queue events:

| Event | Recorded Action |
|-------|-----------------|
| `Illuminate\Queue\Events\JobQueued` | Create pending job |
| `Illuminate\Queue\Events\JobProcessing` | Start processing |
| `Illuminate\Queue\Events\JobProcessed` | Mark completed |
| `Illuminate\Queue\Events\JobFailed` | Mark failed + exception |
| `Illuminate\Queue\Events\JobRetryRequested` | Increment attempts |
