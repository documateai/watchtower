# Watchtower

[![Latest Version on Packagist](https://img.shields.io/packagist/v/documateai/watchtower.svg?style=flat-square)](https://packagist.org/packages/documateai/watchtower)
[![PHP Version](https://img.shields.io/packagist/php-v/documateai/watchtower.svg?style=flat-square)](https://packagist.org/packages/documateai/watchtower)
[![License](https://img.shields.io/packagist/l/documateai/watchtower.svg?style=flat-square)](LICENSE.md)

> Cross-platform Laravel queue monitoring and worker management dashboard

Watchtower provides queue monitoring and worker management capabilities similar to Laravel Horizon, but with full cross-platform support including Windows. Unlike Horizon, which relies on PCNTL signals (Unix-only), Watchtower uses a polling-based approach for worker control that works on Windows, Linux, and macOS.

## Features

- 📊 **Queue Monitoring Dashboard** - Real-time job tracking, status monitoring, and metrics
- ⚙️ **Worker Management** - Start, stop, pause, resume workers from the web UI
- 🖥️ **Cross-Platform** - Works on Windows, Linux, and macOS
- 📋 **Job Tracking** - Job status, payload, exceptions, retries, worker info
- 🎨 **Modern UI** - Alpine.js dark-themed dashboard (no build step required)
- 🗑️ **Automatic Cleanup** - Time-based pruning of old job records

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Redis (optional -- required only when using the default `redis` command bus driver)

## Installation

```bash
composer require documateai/watchtower
```

Publish the configuration and assets:

```bash
php artisan vendor:publish --tag=watchtower-config
php artisan vendor:publish --tag=watchtower-migrations
php artisan migrate
```

## Configuration

The package configuration is published to `config/watchtower.php`:

```php
return [
    // Dashboard URL path
    'path' => env('WATCHTOWER_PATH', 'watchtower'),

    // Route middleware
    'middleware' => ['web'],

    // Authorization gate
    'gate' => env('WATCHTOWER_GATE', 'viewWatchtower'),

    // Command bus driver: 'redis' or 'database'
    'command_bus' => env('WATCHTOWER_COMMAND_BUS', 'redis'),

    // Job retention (days)
    'retention' => [
        'completed' => 7,
        'failed' => 30,
    ],

    // Supervisor configuration
    'supervisors' => [
        'default' => [
            'connection' => 'redis',
            'queue' => '*',           // Auto-discover all queues!
            // Or specify explicit queues:
            // 'queue' => ['default', 'emails', 'notifications'],
            'min_processes' => 1,
            'max_processes' => 10,
            'tries' => 3,
            'timeout' => 60,
        ],
    ],
];
```

## Usage

### Starting the Supervisor

Run the supervisor to automatically manage your queue workers:

```bash
php artisan watchtower:supervisor
```

The supervisor will:

- **Auto-discover all queues** in your application (Redis keys, job records)
- Maintain the minimum number of workers
- Restart failed workers automatically
- Monitor worker health via heartbeats

> **Tip:** Set `'queue' => '*'` in your config (default) to automatically detect and process all queues. Or specify explicit queues: `'queue' => ['default', 'emails', 'high']`

### Manual Worker Control

Start a single worker manually:

```bash
php artisan watchtower:worker default
```

### Zero-Downtime Deployments

Gracefully restart all workers after deploying new code:

```bash
php artisan watchtower:restart
```

Options:

- `--queue=emails` - Only restart workers on a specific queue
- `--force` - Force immediate restart (don't wait for current job)

Workers will finish processing their current job, then restart with fresh code.

### Terminating All Processes

Stop the supervisor and all workers (similar to `horizon:terminate`):

```bash
php artisan watchtower:terminate
```

Options:

- `--wait` - Wait for all workers to finish before returning

### Accessing the Dashboard

Visit `/watchtower` in your browser. By default, the dashboard is only accessible in local environments. Configure the gate in your `AuthServiceProvider` for production:

```php
Gate::define('viewWatchtower', function ($user) {
    return in_array($user->email, [
        'admin@example.com',
    ]);
});
```

### Pruning Old Jobs

Watchtower automatically prunes old job records. You can also run the prune command manually:

```bash
php artisan watchtower:prune
```

## How It Works

### Polling-Based Control via CommandBus

Unlike Horizon which uses PCNTL signals (Unix-only), Watchtower uses a `CommandBusInterface` for worker control with two drivers:

- **Redis** (default) -- fast, uses `Redis::connection()`
- **Database** -- no Redis required, uses `watchtower_commands` table

```
WATCHTOWER_COMMAND_BUS=database  # set in .env to use database driver
```

1. Dashboard sends command via CommandBus: `$commandBus->put("watchtower:worker:{id}:command", "stop")`
2. Worker polls the CommandBus every 3 seconds
3. Worker reads and executes the command
4. Worker confirms status in database

This approach provides:

- ✅ Cross-platform compatibility
- ✅ No PCNTL dependency
- ✅ Redis optional (database driver available)
- ✅ Simple debugging
- ⚠️ 1-3 second response delay (acceptable for worker management)

### Dashboard Updates

The dashboard polls for updates every 3 seconds (configurable). This provides near-real-time visibility into:

- Job counts and status
- Worker health and activity
- Throughput metrics

## Artisan Commands

| Command | Description |
|---------|-------------|
| `watchtower:supervisor` | Start the supervisor to manage workers |
| `watchtower:worker {queue}` | Start a single worker process |
| `watchtower:restart` | Gracefully restart all workers |
| `watchtower:terminate` | Stop supervisor and all workers |
| `watchtower:status` | Show current supervisor and worker status |
| `watchtower:prune` | Prune old job records |

## License

MIT License. See [LICENSE.md](LICENSE.md) for details.

## Contributing

Contributions are welcome! Please see [CONTRIBUTING.md](CONTRIBUTING.md) for details.

## Security

If you discover a security vulnerability, please send an email instead of using the issue tracker. See [SECURITY.md](SECURITY.md) for details.

## Credits

- [Nathan Phelps](https://github.com/nathanphelps)
- [Claude Code](https://claude.ai) - AI pair programming assistant
- [All Contributors](../../contributors)
