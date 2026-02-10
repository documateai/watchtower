# Watchtower Documentation Index

Cross-platform Laravel queue monitoring and worker management dashboard.

## Table of Contents

1. **[Installation Guide](./watchtower-installation.md)** - Getting started with Watchtower
2. **[Configuration Reference](./watchtower-configuration.md)** - All configuration options
3. **[Architecture Overview](./watchtower-architecture.md)** - How Watchtower works
4. **[API Reference](./watchtower-api-reference.md)** - Services, commands, and endpoints
5. **[Dashboard Guide](./watchtower-dashboard.md)** - Using the web interface
6. **[Design Document](./2026-02-04-watchtower-design.md)** - Original design specification

## Quick Start

```bash
# Install package
composer require nathanphelps/watchtower

# Publish config and run migrations
php artisan vendor:publish --tag=watchtower-config
php artisan vendor:publish --tag=watchtower-migrations
php artisan migrate

# Start the supervisor
php artisan watchtower:supervisor
```

Visit `/watchtower` in your browser to access the dashboard.

## Why Watchtower?

| Feature | Laravel Horizon | Watchtower |
|---------|-----------------|------------|
| Windows Support | ❌ No | ✅ Yes |
| Linux/macOS Support | ✅ Yes | ✅ Yes |
| Web Dashboard | ✅ Yes | ✅ Yes |
| Worker Control | ✅ Via signals | ✅ Via CommandBus polling |
| Auto-scaling | ✅ Yes | ⚠️ Manual |
| Redis Required | ✅ Yes | ⚠️ Optional (database driver available) |

Watchtower uses a **polling-based control mechanism** instead of PCNTL signals, making it fully cross-platform compatible. The control channel (`CommandBusInterface`) supports both Redis and Database drivers, so Redis is not required.
