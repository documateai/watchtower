# Changelog

All notable changes to `watchtower` will be documented in this file.

## [1.0.0] - 2026-02-04

### Added

- **Queue Monitoring Dashboard** - Real-time job tracking with auto-refresh
- **Worker Management** - Start, stop, pause, resume workers from web UI
- **Queue Auto-Discovery** - Set `'queue' => '*'` to automatically detect all queues
- **Zero-Downtime Restarts** - `watchtower:restart` command for graceful worker restarts
- **Graceful Termination** - `watchtower:terminate` command to stop all processes
- **Cross-Platform Support** - Works on Windows, Linux, and macOS
- **Job Tracking** - Full lifecycle (pending → processing → completed/failed)
- **Failed Job Management** - View exceptions, retry failed jobs, delete records
- **Metrics Dashboard** - Hourly throughput, queue depths, average durations
- **Automatic Pruning** - Time-based cleanup via `watchtower:prune` command

### Core Components

- `WorkerManager` service using Symfony Process for cross-platform worker spawning
- `WorkerCommand` artisan command with Redis polling for stop/pause/resume
- `SupervisorCommand` for automatic worker lifecycle management
- `MetricsCollector` for aggregating job statistics
- `PruneJobsCommand` for cleanup of old job records
- `JobMonitor` service capturing queue events
- `Job` and `Worker` Eloquent models

### Dashboard

- Standalone Blade templates with Alpine.js (no Vite build required)
- Dark theme with responsive design
- Real-time polling every 3 seconds (configurable)
- Gate-based authorization (`viewWatchtower`)

### Technical Details

- Redis-based control plane for worker commands
- Database-backed job and worker state
- No PCNTL dependency (polling-based control)
- Laravel 11/12 compatible
- Comprehensive documentation in `docs/plans/`
