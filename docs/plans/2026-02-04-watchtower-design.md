# Watchtower Design Document

**Package Name:** `nathanphelps/watchtower`
**Version:** 1.0.0
**Date:** 2026-02-04
**Purpose:** Cross-platform Laravel queue monitoring and worker management dashboard

---

## Overview

Watchtower is a Laravel package that provides queue monitoring and worker management capabilities similar to Laravel Horizon, but with full cross-platform support including Windows. Unlike Horizon, which relies on PCNTL signals (Unix-only), Watchtower uses a polling-based approach for worker control that works on Windows, Linux, and macOS.

### Key Features

- **Queue Monitoring Dashboard** - Real-time job tracking, status monitoring, and metrics
- **Worker Management** - Start, stop, pause, resume workers and manually scale worker count
- **Cross-Platform** - Works on Windows, Linux, and macOS
- **Standard Job Tracking** - Job status, payload, exceptions, retries, worker info
- **Modern UI** - Inertia.js + Vue.js SPA interface
- **Configurable Retention** - Time-based automatic pruning of old job records

---

## Architecture

### High-Level Components

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
│  │  Processes   │                     │   Processes  │      │
│  └──────────────┘                     └──────────────┘      │
│                                                               │
│  ┌──────────────┐                                            │
│  │  Dashboard   │◀───polls (3s)────┐                        │
│  │ (Inertia+Vue)│                   │                        │
│  └──────────────┘                   │                        │
│         │                     ┌──────┴───────┐               │
│         └────sends commands──▶│ API          │               │
│                               │ Controllers  │               │
│                               └──────────────┘               │
└─────────────────────────────────────────────────────────────┘
```

### Technology Stack

- **Backend:** PHP 8.1+, Laravel 10+
- **Frontend:** Vue.js 3, Inertia.js 1.0+
- **Process Management:** Symfony Process component
- **Database:** Laravel's default database connection
- **Control Channel:** CommandBus (Redis or Database driver)
- **Build Tools:** Vite

---

## Core Components

### 1. Job Monitor

**Purpose:** Tracks job lifecycle and records data to database

**Implementation:**
- Registers Laravel queue event listeners:
  - `JobProcessing` - Record job start
  - `JobProcessed` - Record successful completion
  - `JobFailed` - Record failure with exception trace
  - `JobRetrying` - Record retry attempts

**Data Captured:**
- Job ID, Queue name, Connection
- Timestamps (queued_at, started_at, completed_at)
- Payload (serialized)
- Status (pending, processing, completed, failed)
- Worker ID that processed it
- Exception details (for failed jobs)
- Retry count

**Service Class:**
```php
namespace NathanPhelps\Watchtower\Services;

class JobMonitor
{
    public function recordJobStarted(JobProcessing $event): void
    public function recordJobCompleted(JobProcessed $event): void
    public function recordJobFailed(JobFailed $event): void
    public function recordJobRetrying(JobRetrying $event): void
}
```

### 2. Worker Manager

**Purpose:** Spawn and manage worker processes using Symfony Process

**Implementation:**
- Uses `Symfony\Component\Process\Process` to spawn workers
- Each worker runs: `php artisan watchtower:worker {queue}`
- Tracks worker PIDs and status
- Cross-platform process control (no PCNTL dependency)

**Service Class:**
```php
namespace NathanPhelps\Watchtower\Services;

use Symfony\Component\Process\Process;

class WorkerManager
{
    public function startWorker(string $queue, array $options = []): string
    public function stopWorker(string $workerId): void
    public function pauseWorker(string $workerId): void
    public function resumeWorker(string $workerId): void
    public function getRunningWorkers(): Collection
    public function isWorkerRunning(string $workerId): bool
}
```

**Worker Process:**
- Runs standard Laravel queue:work loop
- Polls the CommandBus every 3 seconds for control commands
- Commands stored as: `watchtower:worker:{id}:command`
- Available commands: `stop`, `pause`, `resume`

### 3. Supervisor

**Purpose:** Coordinates worker lifecycle and scaling

**Console Command:**
```php
php artisan watchtower:supervisor
```

**Responsibilities:**
- Maintains desired worker count per queue
- Restarts failed workers automatically
- Sends control commands via CommandBus
- Monitors worker health
- Persists worker state to database

**Configuration:**
```php
// config/watchtower.php
'supervisors' => [
    'default' => [
        'connection' => 'redis',
        'queue' => ['default'],
        'balance' => 'auto',
        'minProcesses' => 1,
        'maxProcesses' => 10,
        'tries' => 3,
        'timeout' => 60,
    ],
],
```

### 4. Dashboard (Inertia + Vue)

**Purpose:** Web interface for monitoring and control

**Routes:**
```php
// routes/web.php (registered by service provider)
Route::middleware(['web', 'watchtower'])->prefix('watchtower')->group(function () {
    Route::get('/', 'DashboardController@index');
    Route::get('/jobs', 'JobsController@index');
    Route::get('/jobs/{id}', 'JobsController@show');
    Route::get('/failed', 'FailedJobsController@index');
    Route::post('/failed/{id}/retry', 'FailedJobsController@retry');
    Route::get('/workers', 'WorkersController@index');
    Route::post('/workers/start', 'WorkersController@start');
    Route::post('/workers/{id}/stop', 'WorkersController@stop');
    Route::post('/workers/{id}/pause', 'WorkersController@pause');
    Route::post('/workers/{id}/resume', 'WorkersController@resume');
    Route::get('/metrics', 'MetricsController@index');
});
```

**Vue Components:**
```
resources/js/
├── Pages/
│   ├── Dashboard.vue          # Overview: recent jobs, metrics
│   ├── Jobs/
│   │   ├── Index.vue          # Job list with filters
│   │   └── Show.vue           # Job detail view
│   ├── FailedJobs.vue         # Failed jobs list
│   ├── Workers.vue            # Worker management
│   └── Metrics.vue            # Throughput, timing charts
├── Components/
│   ├── JobRow.vue
│   ├── WorkerCard.vue
│   ├── StatusBadge.vue
│   └── MetricsChart.vue
└── app.js                     # Inertia app setup
```

**Polling Mechanism:**
- Dashboard polls `/watchtower/api/poll` every 3 seconds
- Returns: job counts, recent jobs, worker status, metrics
- Uses Vue's `setInterval` in mounted hook
- Clears interval on component unmount

### 5. API Layer

**Purpose:** Serve data to dashboard, handle control commands

**Controllers:**
```php
namespace NathanPhelps\Watchtower\Controllers;

class DashboardController
{
    public function index(): Response
    {
        return Inertia::render('Dashboard', [
            'stats' => $this->metricsCollector->getStats(),
            'recentJobs' => $this->jobRepository->recent(20),
            'workers' => $this->workerManager->getRunningWorkers(),
        ]);
    }
}

class WorkersController
{
    public function start(Request $request): Response
    {
        $workerId = $this->workerManager->startWorker(
            queue: $request->input('queue'),
            options: $request->input('options', [])
        );

        return back()->with('success', "Worker {$workerId} started");
    }

    public function stop(string $id): Response
    {
        $this->workerManager->stopWorker($id);
        return back();
    }
}
```

---

## Data Models

### Database Schema

**`watchtower_jobs` Table:**
```php
Schema::create('watchtower_jobs', function (Blueprint $table) {
    $table->id();
    $table->string('job_id')->unique()->index();
    $table->string('queue')->index();
    $table->string('connection');
    $table->text('payload');
    $table->string('status')->index(); // pending, processing, completed, failed
    $table->foreignId('worker_id')->nullable()->index();
    $table->integer('attempts')->default(0);
    $table->text('exception')->nullable();
    $table->timestamp('queued_at')->index();
    $table->timestamp('started_at')->nullable();
    $table->timestamp('completed_at')->nullable();
    $table->timestamps();

    $table->index(['status', 'queued_at']);
    $table->index(['queue', 'status']);
});
```

**`watchtower_workers` Table:**
```php
Schema::create('watchtower_workers', function (Blueprint $table) {
    $table->id();
    $table->string('worker_id')->unique()->index();
    $table->string('supervisor');
    $table->string('queue');
    $table->integer('pid')->nullable();
    $table->string('status'); // running, paused, stopped
    $table->timestamp('started_at');
    $table->timestamp('last_heartbeat')->nullable();
    $table->timestamps();
});
```

**`watchtower_metrics` Table (Optional - for historical data):**
```php
Schema::create('watchtower_metrics', function (Blueprint $table) {
    $table->id();
    $table->string('queue')->index();
    $table->integer('jobs_processed');
    $table->integer('jobs_failed');
    $table->float('avg_duration'); // seconds
    $table->timestamp('period_start');
    $table->timestamp('period_end');
    $table->timestamps();

    $table->index(['queue', 'period_start']);
});
```

### Eloquent Models

**Job Model:**
```php
namespace NathanPhelps\Watchtower\Models;

class Job extends Model
{
    protected $table = 'watchtower_jobs';

    protected $casts = [
        'payload' => 'array',
        'queued_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function worker(): BelongsTo
    public function isCompleted(): bool
    public function isFailed(): bool
    public function isPending(): bool
}
```

**Worker Model:**
```php
namespace NathanPhelps\Watchtower\Models;

class Worker extends Model
{
    protected $table = 'watchtower_workers';

    protected $casts = [
        'started_at' => 'datetime',
        'last_heartbeat' => 'datetime',
    ];

    public function jobs(): HasMany
    public function isRunning(): bool
    public function isPaused(): bool
}
```

---

## Worker Control Protocol

### Polling-Based Control via CommandBus

Worker control is abstracted behind a `CommandBusInterface` (`src/Contracts/CommandBusInterface.php`) with two drivers:
- **Redis** (default) - uses `Redis::connection()->set/get/del`
- **Database** - uses `watchtower_commands` table with TTL-based expiration

**How it works:**
1. Supervisor writes command via CommandBus: `$commandBus->put("watchtower:worker:{id}:command", "stop")`
2. Worker polls CommandBus every 3 seconds during job processing
3. Worker reads command and acts accordingly
4. Worker removes command via `$commandBus->forget()` after executing

**Command Types:**
- `stop` - Finish current job, then exit
- `pause` - Finish current job, then wait for `resume` command
- `resume` - Continue processing jobs (from paused state)

**Worker Loop:**
```php
// In WorkerCommand.php
public function handle(CommandBusInterface $commandBus)
{
    $this->commandBus = $commandBus;
    $workerId = $this->generateWorkerId();
    $this->register($workerId);

    while (true) {
        // Check for commands
        $command = $this->commandBus->get("watchtower:worker:{$workerId}:command");

        if ($command === 'stop') {
            $this->gracefulShutdown();
            break;
        }

        if ($command === 'pause') {
            $this->waitForResume($workerId);
            continue;
        }

        // Process next job
        $this->processNextJob();

        // Send heartbeat
        $this->sendHeartbeat($workerId);

        sleep(3); // Poll interval
    }
}
```

**Benefits of this approach:**
- ✅ Cross-platform (Windows, Linux, macOS)
- ✅ No PCNTL dependency
- ✅ Redis optional (database driver available)
- ✅ Simple to implement and debug
- ✅ Reliable (no signal handling edge cases)
- ⚠️ 1-3 second response delay (acceptable for worker management)

---

## Authentication & Authorization

### Gate-Based Access Control

**Configuration:**
```php
// config/watchtower.php
return [
    'path' => 'watchtower',

    'middleware' => ['web'],

    'gate' => env('WATCHTOWER_GATE', 'viewWatchtower'),
];
```

**Gate Registration (in service provider):**
```php
Gate::define('viewWatchtower', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

**Usage:**
```php
// Applied to all Watchtower routes
Route::middleware(['web', 'can:viewWatchtower'])->group(...)
```

**Production Recommendation:**
- Use environment-based gate in development
- Integrate with existing authorization system in production
- Consider IP whitelisting for additional security

---

## Configuration

### Package Configuration File

**`config/watchtower.php`:**
```php
<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Watchtower Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Watchtower will be accessible from.
    |
    */
    'path' => env('WATCHTOWER_PATH', 'watchtower'),

    /*
    |--------------------------------------------------------------------------
    | Watchtower Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Watchtower route.
    |
    */
    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Watchtower Gate
    |--------------------------------------------------------------------------
    |
    | This gate determines who can access Watchtower in non-local environments.
    |
    */
    'gate' => env('WATCHTOWER_GATE', 'viewWatchtower'),

    /*
    |--------------------------------------------------------------------------
    | Job Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep job records before pruning. Set to null to keep forever.
    |
    */
    'retention' => [
        'completed' => env('WATCHTOWER_RETENTION_COMPLETED', 7), // days
        'failed' => env('WATCHTOWER_RETENTION_FAILED', 30), // days
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Bus Driver
    |--------------------------------------------------------------------------
    |
    | The driver used for worker control commands.
    | Supported: "redis", "database"
    |
    */
    'command_bus' => env('WATCHTOWER_COMMAND_BUS', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Polling Interval
    |--------------------------------------------------------------------------
    |
    | How often workers should check for control commands (in seconds).
    |
    */
    'worker_poll_interval' => env('WATCHTOWER_WORKER_POLL_INTERVAL', 3),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Polling Interval
    |--------------------------------------------------------------------------
    |
    | How often the dashboard should poll for updates (in milliseconds).
    |
    */
    'dashboard_poll_interval' => env('WATCHTOWER_DASHBOARD_POLL_INTERVAL', 3000),

    /*
    |--------------------------------------------------------------------------
    | Supervisors
    |--------------------------------------------------------------------------
    |
    | Define your supervisor configurations here.
    |
    */
    'supervisors' => [
        'default' => [
            'connection' => env('WATCHTOWER_CONNECTION', 'redis'),
            'queue' => ['default'],
            'balance' => 'auto',
            'minProcesses' => 1,
            'maxProcesses' => 10,
            'tries' => 3,
            'timeout' => 60,
        ],
    ],
];
```

---

## Implementation Plan

### Phase 1: Foundation (Core Infrastructure)

**Goals:**
- Set up package structure
- Database migrations
- Service provider with basic registration
- Job monitoring (event listeners)

**Deliverables:**
- Package skeleton with proper namespacing
- Migrations for `watchtower_jobs`, `watchtower_workers`
- `WatchtowerServiceProvider` with event listener registration
- `JobMonitor` service to capture queue events
- Basic `Job` and `Worker` Eloquent models

**Testing:**
- Jobs are recorded to database when dispatched
- Job status updates correctly (processing → completed/failed)

### Phase 2: Worker Management

**Goals:**
- Worker spawning and control
- Supervisor command
- Redis-based control protocol

**Deliverables:**
- `WorkerManager` service using Symfony Process
- `WorkerCommand` (artisan command for worker process)
- CommandBus polling mechanism for worker control (Redis or Database driver)
- `SupervisorCommand` to manage worker lifecycle
- Worker heartbeat system

**Testing:**
- Workers can be started via WorkerManager
- Workers respond to stop/pause/resume commands within 3 seconds
- Supervisor maintains desired worker count

### Phase 3: Dashboard UI

**Goals:**
- Inertia + Vue setup
- Core dashboard pages
- Basic job listing and detail views

**Deliverables:**
- Inertia.js integration with Watchtower routes
- Vue 3 app setup with Vite
- Dashboard controller with polling endpoint
- Vue components: Dashboard, Jobs list, Job detail
- Status badges and basic styling

**Testing:**
- Dashboard accessible at `/watchtower`
- Job list updates via polling
- Job details display payload and exceptions

### Phase 4: Worker Control UI

**Goals:**
- Worker management interface
- Start/stop/pause/resume controls
- Worker status display

**Deliverables:**
- Workers page with worker cards
- API endpoints for worker control
- Vue components for worker management
- Real-time worker status via polling

**Testing:**
- Workers can be started from dashboard
- Stop/pause/resume buttons work correctly
- Worker status reflects actual state

### Phase 5: Failed Jobs & Retry

**Goals:**
- Failed jobs interface
- Retry functionality
- Exception display

**Deliverables:**
- Failed jobs page
- Retry job endpoint
- Exception stack trace display
- Failed job filtering

**Testing:**
- Failed jobs appear in failed jobs list
- Retry button re-queues jobs correctly
- Exception traces are readable

### Phase 6: Metrics & Polish

**Goals:**
- Basic metrics collection
- Job throughput display
- Configuration polish
- Documentation

**Deliverables:**
- `MetricsCollector` service
- Metrics dashboard page
- Charts for job throughput and timing
- Configuration validation
- README with installation and usage docs

**Testing:**
- Metrics accurately reflect job processing rates
- Charts display correctly
- Documentation is clear and complete

### Phase 7: Cleanup & Release

**Goals:**
- Automated pruning
- Performance optimization
- Package release

**Deliverables:**
- `watchtower:prune` command with scheduler
- Query optimization (indexes, eager loading)
- Asset compilation for production
- Tagged release on GitHub

**Testing:**
- Prune command removes old records correctly
- Dashboard performs well with thousands of jobs
- Package installs cleanly via Composer

---

## Technical Considerations

### Cross-Platform Compatibility

**Windows-specific considerations:**
- Use Symfony Process for spawning (no `fork()`)
- Avoid POSIX-specific functions
- Test path handling (backslashes vs forward slashes)
- Handle line endings (CRLF vs LF)

**Testing strategy:**
- Automated tests on GitHub Actions (Windows, Linux, macOS)
- Manual testing on Windows 10/11
- Manual testing on Ubuntu/Debian
- Manual testing on macOS

### Performance

**Database optimization:**
- Indexes on frequently queried columns (status, queue, timestamps)
- Partition old records if retention period is long
- Use Redis for recent job caching (optional enhancement)

**Polling optimization:**
- Dashboard batches all data in single polling request
- Workers use efficient Redis commands (GET, not SCAN)
- Limit dashboard job list to recent 100-500 jobs

### Security

**Access control:**
- Gate-based authorization (configurable)
- CSRF protection on control endpoints
- Input validation on worker start parameters
- Sanitize job payload display (prevent XSS)

**Production recommendations:**
- Use HTTPS in production
- Restrict access via gate or middleware
- Consider IP whitelisting
- Don't expose sensitive data in job payloads

---

## Open Questions / Future Enhancements

### Potential Future Features

1. **Auto-scaling** - Automatically adjust worker count based on queue depth
2. **Job tagging** - Custom tags for filtering and grouping jobs
3. **Batch tracking** - Track Laravel job batches
4. **Webhook notifications** - Alert on failures or queue depth thresholds
5. **Multi-connection support** - Monitor jobs across multiple queue connections
6. **Export functionality** - Export job data to CSV/JSON
7. **Advanced metrics** - Memory usage, CPU usage, custom metrics
8. **WebSocket support** - Optional real-time updates (for those who want it)

### Known Limitations

1. **Polling delay** - 3-second delay in worker commands (vs instant signals)
2. **Historical metrics** - Basic metrics only (no advanced trending)
3. **Single server** - Assumes single server deployment (no distributed coordination)
4. **No job prioritization** - Uses Laravel's standard queue priority

---

## Success Criteria

### Definition of Done

- ✅ Package installs via Composer on Windows, Linux, macOS
- ✅ Dashboard displays jobs with correct status
- ✅ Workers can be started, stopped, paused, resumed from dashboard
- ✅ Failed jobs can be retried
- ✅ Job retention pruning works automatically
- ✅ Gate-based access control functional
- ✅ Documentation complete (README, config comments)
- ✅ Automated tests pass on all platforms
- ✅ Dashboard performs well with 10,000+ job records

### User Experience Goals

- **Simple installation** - Minimal configuration required
- **Familiar interface** - Feels similar to Horizon for easy adoption
- **Reliable** - No crashes or data loss
- **Fast** - Dashboard loads quickly, actions respond promptly
- **Clear** - Error messages and documentation are helpful

---

## Conclusion

Watchtower provides a cross-platform alternative to Laravel Horizon with a focus on simplicity and reliability. By using a polling-based control mechanism instead of PCNTL signals, it works on Windows without sacrificing functionality. The Inertia + Vue dashboard provides a modern, responsive interface for monitoring and managing queue workers.

The phased implementation plan ensures incremental progress with testable milestones. Starting with core job monitoring and worker management, then building up to the full dashboard experience, allows for early validation and course correction.

**Next Steps:**
1. Create package skeleton structure
2. Initialize Composer package with proper namespacing
3. Set up development environment (Laravel app for testing)
4. Begin Phase 1 implementation
