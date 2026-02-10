# Watchtower Installation Guide

## Requirements

- PHP 8.2+
- Laravel 11 or 12
- Redis server (optional -- only required when using the `redis` command bus driver, which is the default)
- Node.js 18+ (for building assets, optional)

## Installation

### 1. Install via Composer

```bash
composer require documateai/watchtower
```

The package will auto-register via Laravel's package discovery.

### 2. Publish Configuration

```bash
php artisan vendor:publish --tag=watchtower-config
```

This creates `config/watchtower.php` with all available options.

### 3. Publish and Run Migrations

```bash
php artisan vendor:publish --tag=watchtower-migrations
php artisan migrate
```

Creates tables:

- `watchtower_jobs` - Tracks all queue jobs
- `watchtower_workers` - Tracks worker processes
- `watchtower_commands` - Command bus storage (used when `command_bus` is `database`)

### 4. Configure Authorization

By default, Watchtower is only accessible in local environments. For production, define the authorization gate in your `AuthServiceProvider`:

```php
use Illuminate\Support\Facades\Gate;

public function boot(): void
{
    Gate::define('viewWatchtower', function ($user) {
        return in_array($user->email, [
            'admin@example.com',
            'developer@example.com',
        ]);
    });
}
```

### 5. Start the Supervisor

```bash
php artisan watchtower:supervisor
```

This will:

- Spawn workers based on your configuration
- Monitor worker health
- Restart failed workers automatically

### 6. Access the Dashboard

Visit `http://your-app.test/watchtower` in your browser.

---

## Production Deployment

### Process Manager

Use a process manager like Supervisor to keep the Watchtower supervisor running:

**/etc/supervisor/conf.d/watchtower.conf:**

```ini
[program:watchtower]
process_name=%(program_name)s
command=php /var/www/html/artisan watchtower:supervisor
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/watchtower.log
stopwaitsecs=3600
```

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start watchtower
```

### Windows Service

On Windows, use NSSM or Task Scheduler:

**Using NSSM:**

```powershell
nssm install Watchtower "C:\php\php.exe" "C:\laravel\artisan watchtower:supervisor"
nssm start Watchtower
```

### Environment Variables

Configure via `.env`:

```env
WATCHTOWER_PATH=watchtower
WATCHTOWER_GATE=viewWatchtower
WATCHTOWER_COMMAND_BUS=redis          # or 'database' if you don't have Redis
WATCHTOWER_RETENTION_COMPLETED=7
WATCHTOWER_RETENTION_FAILED=30
WATCHTOWER_WORKER_POLL_INTERVAL=3
WATCHTOWER_DASHBOARD_POLL_INTERVAL=3000
```

---

## Upgrading

```bash
composer update documateai/watchtower
php artisan vendor:publish --tag=watchtower-migrations
php artisan migrate
```

Check the [CHANGELOG](../../CHANGELOG.md) for breaking changes.

---

## Uninstalling

```bash
# Remove tables
php artisan migrate:rollback --path=database/migrations/2026_01_01_000001_create_watchtower_jobs_table.php
php artisan migrate:rollback --path=database/migrations/2026_01_01_000002_create_watchtower_workers_table.php
php artisan migrate:rollback --path=database/migrations/2026_01_01_000003_create_watchtower_commands_table.php

# Remove package
composer remove documateai/watchtower

# Clean up config
rm config/watchtower.php
```
