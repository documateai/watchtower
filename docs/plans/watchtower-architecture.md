# Watchtower Architecture

Technical overview of how Watchtower works under the hood.

---

## High-Level Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Laravel Application                     │
├─────────────────────────────────────────────────────────────┤
│                                                               │
│  ┌─────────────┐         ┌──────────────┐                   │
│  │   Queue     │────────▶│ Job Monitor  │                   │
│  │   Events    │         │  (Listeners) │                   │
│  └─────────────┘         └──────┬───────┘                   │
│                                  │                            │
│                                  ▼                            │
│                          ┌──────────────┐                    │
│                          │   Database   │                    │
│                          │ (Job Records)│                    │
│                          └──────────────┘                    │
│                                                               │
│  ┌──────────────┐       ┌──────────────┐                    │
│  │  Supervisor  │◀─────▶│  CommandBus  │◀────┐              │
│  │   Command    │       │(Redis or DB) │     │              │
│  └──────┬───────┘       └──────────────┘     │              │
│         │                                     │              │
│         │ spawns/manages                      │ polls        │
│         ▼                                     │              │
│  ┌──────────────┐                     ┌──────┴───────┐      │
│  │   Worker     │────────────────────▶│    Worker    │      │
│  │  Processes   │   (Symfony Process) │   Processes  │      │
│  └──────────────┘                     └──────────────┘      │
│                                                               │
│  ┌──────────────┐                                            │
│  │  Dashboard   │◀───polls (3s)────┐                        │
│  │(Blade+Alpine)│                   │                        │
│  └──────────────┘                   │                        │
│         │                     ┌──────┴───────┐               │
│         └────sends commands──▶│ API          │               │
│                               │ Controllers  │               │
│                               └──────────────┘               │
└─────────────────────────────────────────────────────────────┘
```

---

## Core Components

### 1. Job Monitor

**Location:** `src/Services/JobMonitor.php`

Listens to Laravel queue events and records job lifecycle:

| Event | Action |
|-------|--------|
| `JobQueued` | Create job record (status: pending) |
| `JobProcessing` | Update to processing, record worker ID |
| `JobProcessed` | Update to completed |
| `JobFailed` | Update to failed, store exception |
| `JobRetryRequested` | Increment attempts counter |

```php
// Registered in WatchtowerServiceProvider
Event::listen(JobQueued::class, fn($e) => $monitor->recordJobQueued($e));
Event::listen(JobProcessing::class, fn($e) => $monitor->recordJobStarted($e));
// ...
```

### 2. Worker Manager

**Location:** `src/Services/WorkerManager.php`

Manages worker processes using Symfony Process:

```php
// Start worker
$process = new Process(['php', 'artisan', 'watchtower:worker', $queue]);
$process->start();

// Send stop command via CommandBus (Redis or Database)
$commandBus->put("watchtower:worker:{$id}:command", "stop");
```

### 3. Worker Command

**Location:** `src/Commands/WorkerCommand.php`

The actual worker process that:

1. Registers itself in database
2. Processes jobs from queue
3. Polls the CommandBus every 3s for commands
4. Sends heartbeat updates

```php
while (!$this->shouldStop) {
    $command = $this->commandBus->get("watchtower:worker:{$id}:command");

    if ($command === 'stop') break;
    if ($command === 'pause') $this->waitWhilePaused();

    $worker->runNextJob(...);
    $this->sendHeartbeat();
}
```

### 4. Supervisor Command

**Location:** `src/Commands/SupervisorCommand.php`

Orchestrates worker lifecycle:

- Maintains minimum worker count
- Restarts dead workers
- Monitors health via heartbeats
- Cleans up stale records

---

## Cross-Platform Control Protocol

### The Problem with PCNTL

Laravel Horizon uses PCNTL signals (`SIGTERM`, `SIGUSR2`) for worker control. PCNTL is **Unix-only** - it doesn't exist on Windows.

### Watchtower's Solution: CommandBus Polling

Worker control is abstracted behind a `CommandBusInterface` with two drivers:

- **Redis** (default) - uses `Redis::connection()->set/get/del`
- **Database** - uses the `watchtower_commands` table with TTL-based expiration

```
┌───────────────┐    put(key, cmd)   ┌───────────────┐
│   Dashboard   │ ─────────────────▶ │  CommandBus   │
│   (Browser)   │                    │(Redis or DB)  │
└───────────────┘                    └───────┬───────┘
                                             │
                                     get() every 3s
                                             │
                                             ▼
                                     ┌───────────────┐
                                     │    Worker     │
                                     │   Process     │
                                     └───────────────┘
```

**Command Flow:**

1. User clicks "Stop Worker" in dashboard
2. Controller calls `WorkerManager::stopWorker($id)`
3. WorkerManager writes via CommandBus: `put("watchtower:worker:{id}:command", "stop")`
4. Worker polls CommandBus during job processing loop
5. Worker reads command, finishes current job, exits gracefully

**Trade-offs:**

| Aspect | PCNTL Signals | CommandBus Polling |
|--------|---------------|---------------------|
| Response Time | Instant | 1-3 seconds |
| Platform Support | Unix only | Cross-platform |
| Redis Required | N/A | No (database driver available) |
| Complexity | Signal handlers | Simple loop |
| Reliability | Edge cases | Predictable |

---

## Database Schema

### `watchtower_jobs`

```sql
CREATE TABLE watchtower_jobs (
    id BIGINT PRIMARY KEY,
    job_id VARCHAR(255) UNIQUE,     -- Laravel job UUID
    queue VARCHAR(255),              -- Queue name
    connection VARCHAR(255),         -- Connection (redis, database)
    payload LONGTEXT,                -- Serialized job data
    status VARCHAR(255),             -- pending, processing, completed, failed
    worker_id BIGINT,                -- FK to watchtower_workers
    attempts INT DEFAULT 0,
    exception LONGTEXT,              -- Error trace for failed jobs
    queued_at TIMESTAMP,
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX (status, queued_at),
    INDEX (queue, status)
);
```

### `watchtower_workers`

```sql
CREATE TABLE watchtower_workers (
    id BIGINT PRIMARY KEY,
    worker_id VARCHAR(255) UNIQUE,  -- UUID
    supervisor VARCHAR(255),         -- Supervisor name
    queue VARCHAR(255),              -- Queue being processed
    pid INT,                         -- OS process ID
    status VARCHAR(255),             -- running, paused, stopped
    started_at TIMESTAMP,
    last_heartbeat TIMESTAMP,        -- Health check
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    
    INDEX (supervisor, status),
    INDEX (status, last_heartbeat)
);
```

### `watchtower_commands`

Used by the `database` command bus driver to store worker control commands.

```sql
CREATE TABLE watchtower_commands (
    id BIGINT PRIMARY KEY,
    key VARCHAR(255) UNIQUE,       -- Command key (e.g. watchtower:worker:{id}:command)
    value TEXT,                     -- Command value (stop, pause, resume, etc.)
    expires_at TIMESTAMP,          -- TTL-based expiration
    created_at TIMESTAMP
);
```

Expired rows are cleaned inline during `get()` calls.

---

## Dashboard Architecture

### Blade + Alpine.js

The dashboard uses standalone Blade templates with Alpine.js for reactivity:

- **Blade Templates** - Server-rendered HTML (no build step)
- **Alpine.js** - Lightweight reactivity (loaded from CDN)
- **Inline CSS** - Self-contained styling with CSS variables

### Polling Mechanism

```javascript
// dashboard.blade.php (Alpine.js component)
function dashboard() {
    return {
        stats: @json($initialData['stats']),
        polling: true,
        async poll() {
            const response = await fetch('/watchtower/api/poll');
            const data = await response.json();
            this.stats = data.stats;
            this.recentJobs = data.recentJobs;
            this.workers = data.workers;
        }
    };
}
```

### Route Structure

| Route | Controller | Purpose |
|-------|------------|---------|
| `GET /watchtower` | DashboardController | Main dashboard |
| `GET /watchtower/api/poll` | DashboardController | Polling data |
| `GET /watchtower/jobs` | JobsController | Job list |
| `GET /watchtower/jobs/{id}` | JobsController | Job detail |
| `GET /watchtower/failed` | FailedJobsController | Failed jobs |
| `POST /watchtower/failed/{id}/retry` | FailedJobsController | Retry job |
| `GET /watchtower/workers` | WorkersController | Worker list |
| `POST /watchtower/workers/start` | WorkersController | Start worker |
| `POST /watchtower/workers/{id}/stop` | WorkersController | Stop worker |
| `GET /watchtower/metrics` | MetricsController | Metrics view |

---

## Security Considerations

### Authorization

- Gate-based access control via `viewWatchtower`
- Middleware: `AuthorizeWatchtower`
- Default: Local environment only

### Input Validation

- Worker start: validates queue parameter
- All routes protected by CSRF

### XSS Prevention

- Job payloads displayed in `<pre>` tags
- Blade's automatic escaping
- Alpine.js `x-text` directive (auto-escapes)
