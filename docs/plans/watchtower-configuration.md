# Watchtower Configuration Reference

All configuration options for `config/watchtower.php`.

---

## Dashboard Settings

### `path`

**Type:** `string`  
**Default:** `'watchtower'`  
**Env:** `WATCHTOWER_PATH`

The URI path where Watchtower will be accessible.

```php
'path' => env('WATCHTOWER_PATH', 'watchtower'),
// Dashboard at: https://your-app.com/watchtower
```

### `middleware`

**Type:** `array`  
**Default:** `['web']`

Middleware applied to all Watchtower routes.

```php
'middleware' => ['web', 'auth'],
```

### `gate`

**Type:** `string`  
**Default:** `'viewWatchtower'`  
**Env:** `WATCHTOWER_GATE`

The authorization gate for accessing Watchtower.

```php
'gate' => env('WATCHTOWER_GATE', 'viewWatchtower'),
```

---

## Job Retention

### `retention.completed`

**Type:** `int|null`  
**Default:** `7`  
**Env:** `WATCHTOWER_RETENTION_COMPLETED`

Days to keep completed job records. Set to `null` to keep forever.

### `retention.failed`

**Type:** `int|null`  
**Default:** `30`  
**Env:** `WATCHTOWER_RETENTION_FAILED`

Days to keep failed job records.

```php
'retention' => [
    'completed' => env('WATCHTOWER_RETENTION_COMPLETED', 7),
    'failed' => env('WATCHTOWER_RETENTION_FAILED', 30),
],
```

---

## Command Bus

### `command_bus`

**Type:** `string`
**Default:** `'redis'`
**Env:** `WATCHTOWER_COMMAND_BUS`

The driver used for worker control commands (stop, pause, restart, etc).

| Driver | Description |
|--------|-------------|
| `redis` | Uses Redis for command storage (default, fastest) |
| `database` | Uses `watchtower_commands` table (no Redis required) |

```php
'command_bus' => env('WATCHTOWER_COMMAND_BUS', 'redis'),
```

---

## Polling Intervals

### `worker_poll_interval`

**Type:** `int`
**Default:** `3`
**Env:** `WATCHTOWER_WORKER_POLL_INTERVAL`

How often workers check the command bus for control commands (seconds).

- Lower = faster response to stop/pause
- Higher = less load
- Recommended: 1-5 seconds

### `dashboard_poll_interval`

**Type:** `int`  
**Default:** `3000`  
**Env:** `WATCHTOWER_DASHBOARD_POLL_INTERVAL`

How often the dashboard polls for updates (milliseconds).

```php
'worker_poll_interval' => env('WATCHTOWER_WORKER_POLL_INTERVAL', 3),
'dashboard_poll_interval' => env('WATCHTOWER_DASHBOARD_POLL_INTERVAL', 3000),
```

---

## Connection Settings

### `redis_connection`

**Type:** `string`
**Default:** `'default'`
**Env:** `WATCHTOWER_REDIS_CONNECTION`

Redis connection used when `command_bus` is `redis`. Must match a connection in `config/database.php`.

### `database_connection`

**Type:** `string|null`
**Default:** `null`
**Env:** `WATCHTOWER_DATABASE_CONNECTION`

Database connection for job/worker records and the `database` command bus driver. `null` uses the default connection.

---

## Supervisor Configuration

### `supervisors`

Define one or more supervisor configurations:

```php
'supervisors' => [
    'default' => [
        'connection' => env('WATCHTOWER_SUPERVISOR_CONNECTION', 'redis'),
        'queue' => ['default'],
        'balance' => env('WATCHTOWER_SUPERVISOR_BALANCE', 'simple'),
        'min_processes' => env('WATCHTOWER_SUPERVISOR_MIN_PROCESSES', 1),
        'max_processes' => env('WATCHTOWER_SUPERVISOR_MAX_PROCESSES', 10),
        'tries' => env('WATCHTOWER_SUPERVISOR_TRIES', 3),
        'timeout' => env('WATCHTOWER_SUPERVISOR_TIMEOUT', 60),
        'memory' => env('WATCHTOWER_SUPERVISOR_MEMORY', 128),
        'sleep' => env('WATCHTOWER_SUPERVISOR_SLEEP', 3),
        'rest' => env('WATCHTOWER_SUPERVISOR_REST', 0),
    ],
],
```

| Option | Type | Description |
|--------|------|-------------|
| `connection` | string | Queue connection (redis, database, etc.) |
| `queue` | array | Queues this supervisor processes |
| `balance` | string | `'simple'` (all queues) or `'auto'` (balance across) |
| `min_processes` | int | Minimum workers to maintain |
| `max_processes` | int | Maximum allowed workers |
| `tries` | int | Job retry attempts before failing |
| `timeout` | int | Max job execution time (seconds) |
| `memory` | int | Memory limit per worker (MB) |
| `sleep` | int | Seconds to sleep when queue empty |
| `rest` | int | Rest between jobs (seconds) |

### Multiple Supervisors

```php
'supervisors' => [
    'default' => [
        'queue' => ['default', 'emails'],
        'min_processes' => 2,
    ],
    'heavy' => [
        'queue' => ['reports', 'exports'],
        'min_processes' => 1,
        'max_processes' => 5,
        'timeout' => 300,
        'memory' => 512,
    ],
],
```

---

## Pruning

### `prune.enabled`

**Type:** `bool`  
**Default:** `true`  
**Env:** `WATCHTOWER_PRUNE_ENABLED`

Enable automatic pruning.

### `prune.schedule`

**Type:** `string`  
**Default:** `'daily'`  
**Env:** `WATCHTOWER_PRUNE_SCHEDULE`

Prune schedule: `'daily'`, `'hourly'`, or cron expression.

```php
'prune' => [
    'enabled' => env('WATCHTOWER_PRUNE_ENABLED', true),
    'schedule' => env('WATCHTOWER_PRUNE_SCHEDULE', 'daily'),
],
```

---

## Full Configuration Example

```php
<?php

return [
    'path' => 'admin/queues',
    'middleware' => ['web', 'auth', 'can:access-admin'],
    'gate' => 'viewWatchtower',

    'command_bus' => 'redis', // or 'database'

    'retention' => [
        'completed' => 3,
        'failed' => 14,
    ],

    'worker_poll_interval' => 2,
    'dashboard_poll_interval' => 2000,

    'redis_connection' => 'default',
    'database_connection' => null,
    
    'supervisors' => [
        'web' => [
            'connection' => 'redis',
            'queue' => ['default', 'notifications'],
            'min_processes' => 2,
            'max_processes' => 8,
            'tries' => 3,
            'timeout' => 60,
        ],
        'batch' => [
            'connection' => 'redis',
            'queue' => ['reports', 'exports'],
            'min_processes' => 1,
            'max_processes' => 4,
            'timeout' => 600,
            'memory' => 256,
        ],
    ],
    
    'prune' => [
        'enabled' => true,
        'schedule' => '0 3 * * *', // 3 AM daily
    ],
];
```
