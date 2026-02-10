<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Watchtower Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where Watchtower will be accessible from. Feel
    | free to change this path to anything you like.
    |
    */

    'path' => env('WATCHTOWER_PATH', 'watchtower'),

    /*
    |--------------------------------------------------------------------------
    | Watchtower Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will be assigned to every Watchtower route, giving you
    | the chance to add your own middleware to this list or change any of
    | the existing middleware.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Watchtower Gate
    |--------------------------------------------------------------------------
    |
    | This gate determines who can access Watchtower in non-local environments.
    | By default, only local developers are allowed. You can configure this
    | in your AuthServiceProvider.
    |
    */

    'gate' => env('WATCHTOWER_GATE', 'viewWatchtower'),

    /*
    |--------------------------------------------------------------------------
    | Job Retention
    |--------------------------------------------------------------------------
    |
    | How long to keep job records before pruning. Set to null to keep forever.
    | Values are in days.
    |
    */

    'retention' => [
        'completed' => env('WATCHTOWER_RETENTION_COMPLETED', 7),
        'failed' => env('WATCHTOWER_RETENTION_FAILED', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Command Bus Driver
    |--------------------------------------------------------------------------
    |
    | The driver used for worker control commands (stop, pause, restart, etc).
    | Supported: "redis", "database"
    |
    */

    'command_bus' => env('WATCHTOWER_COMMAND_BUS', 'redis'),

    /*
    |--------------------------------------------------------------------------
    | Worker Polling Interval
    |--------------------------------------------------------------------------
    |
    | How often workers should check for control commands (in seconds).
    | Lower values provide faster response to stop/pause commands but
    | increase load. Recommended: 1-5 seconds.
    |
    */

    'worker_poll_interval' => env('WATCHTOWER_WORKER_POLL_INTERVAL', 3),

    /*
    |--------------------------------------------------------------------------
    | Dashboard Polling Interval
    |--------------------------------------------------------------------------
    |
    | How often the dashboard should poll for updates (in milliseconds).
    | Lower values provide more real-time feel but increase server load.
    | Recommended: 2000-5000 milliseconds.
    |
    */

    'dashboard_poll_interval' => env('WATCHTOWER_DASHBOARD_POLL_INTERVAL', 3000),

    /*
    |--------------------------------------------------------------------------
    | Redis Connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection to use for worker control commands and monitoring.
    | This should match one of your Redis connections in config/database.php.
    |
    */

    'redis_connection' => env('WATCHTOWER_REDIS_CONNECTION', 'default'),

    /*
    |--------------------------------------------------------------------------
    | Database Connection
    |--------------------------------------------------------------------------
    |
    | The database connection to use for storing job records and worker state.
    | Set to null to use your default connection.
    |
    */

    'database_connection' => env('WATCHTOWER_DATABASE_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Supervisors
    |--------------------------------------------------------------------------
    |
    | Define your supervisor configurations here. Each supervisor manages a
    | set of workers for specific queues. You can define multiple supervisors
    | for different queue configurations.
    |
    */

    'supervisors' => [
        'default' => [
            // The queue connection to monitor (redis, database, etc.)
            'connection' => env('WATCHTOWER_SUPERVISOR_CONNECTION', 'redis'),

            // The queues this supervisor should process
            // Use '*' to auto-discover all queues, or specify an array:
            // 'queue' => ['default', 'emails', 'notifications'],
            'queue' => env('WATCHTOWER_SUPERVISOR_QUEUE', '*'),

            // How to distribute workers across queues
            // Options: 'simple' (each worker processes ALL queues - default, best for most cases),
            //          'auto' (distribute workers across queues via round-robin - requires min_processes >= queue count)
            'balance' => env('WATCHTOWER_SUPERVISOR_BALANCE', 'simple'),

            // Minimum number of workers to maintain
            'min_processes' => env('WATCHTOWER_SUPERVISOR_MIN_PROCESSES', 1),

            // Maximum number of workers allowed
            'max_processes' => env('WATCHTOWER_SUPERVISOR_MAX_PROCESSES', 10),

            // Number of times to attempt a job before marking as failed
            'tries' => env('WATCHTOWER_SUPERVISOR_TRIES', 3),

            // Maximum execution time for a single job (seconds)
            'timeout' => env('WATCHTOWER_SUPERVISOR_TIMEOUT', 60),

            // Maximum memory the worker may consume (MB)
            'memory' => env('WATCHTOWER_SUPERVISOR_MEMORY', 128),

            // Number of seconds to wait before restarting a worker
            'sleep' => env('WATCHTOWER_SUPERVISOR_SLEEP', 3),

            // Number of seconds to rest between jobs when queue is empty
            'rest' => env('WATCHTOWER_SUPERVISOR_REST', 0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prune Schedule
    |--------------------------------------------------------------------------
    |
    | Automatically prune old job records on a schedule. You can disable this
    | and manually run the prune command if preferred.
    |
    */

    'prune' => [
        'enabled' => env('WATCHTOWER_PRUNE_ENABLED', true),
        'schedule' => env('WATCHTOWER_PRUNE_SCHEDULE', 'daily'), // daily, hourly, or cron expression
    ],
];
