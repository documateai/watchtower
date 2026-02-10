# Plan: Abstract Control Channel with CommandBus Interface

**Status: Implemented**

## Summary

Replaced all hardcoded Redis usage in the worker control plane with a `CommandBusInterface`, providing both Redis and Database implementations. Redis is no longer required -- users without Redis can use database polling for worker control.

## What Was Done

### New Files Created

| File | Purpose |
|------|---------|
| `src/Contracts/CommandBusInterface.php` | Interface with `put()`, `get()`, `forget()` |
| `src/Services/CommandBus/RedisCommandBus.php` | Redis implementation (wraps `Redis::connection()`) |
| `src/Services/CommandBus/DatabaseCommandBus.php` | Database implementation (uses `watchtower_commands` table) |
| `database/migrations/2026_01_01_000003_create_watchtower_commands_table.php` | Migration for command bus database driver |
| `phpunit.xml.dist` | PHPUnit configuration |

### Files Modified

| File | Change |
|------|--------|
| `config/watchtower.php` | Added `command_bus` config key (`redis` or `database`) |
| `src/WatchtowerServiceProvider.php` | Registers `CommandBusInterface` singleton based on config |
| `src/Services/WorkerManager.php` | Constructor-injected `CommandBusInterface`, replaces direct Redis in `sendCommand()` |
| `src/Commands/WorkerCommand.php` | Resolves `CommandBusInterface` in `handle()`, replaces Redis in `checkForCommands()` and `gracefulShutdown()` |
| `src/Commands/SupervisorCommand.php` | Resolves `CommandBusInterface` in `handle()`, replaces Redis in `shouldTerminate()` |
| `src/Commands/RestartCommand.php` | Resolves `CommandBusInterface` in `handle()`, replaces all Redis calls |
| `src/Commands/TerminateCommand.php` | Resolves `CommandBusInterface` in `handle()`, replaces all Redis calls |

### What Stayed the Same

- Queue discovery in `WorkerManager::discoverQueues()` -- Redis scanning there reads queue driver internals, not the control plane
- All database models, JobMonitor, MetricsCollector -- no Redis dependency
- Key naming conventions (`watchtower:worker:{id}:command`, `watchtower:terminate`, etc.)
- Fail-silent error handling pattern throughout

## Configuration

```env
# Set the command bus driver (default: redis)
WATCHTOWER_COMMAND_BUS=redis    # or 'database'
```

When using `database`, the `watchtower_commands` table stores control commands with TTL-based expiration. Expired rows are cleaned inline during `get()` calls.
