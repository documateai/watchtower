# Watchtower Dashboard Guide

Using the web interface to monitor and manage your queues.

---

## Accessing the Dashboard

Navigate to your configured path (default: `/watchtower`).

**Authorization:**

- Local environment: Accessible by default
- Production: Requires passing the `viewWatchtower` gate

---

## Dashboard Overview

The main dashboard shows:

### Stats Cards

Real-time counts for:

- **Pending** - Jobs waiting to process
- **Processing** - Currently running
- **Completed** - Successfully finished
- **Failed** - Jobs that threw exceptions
- **Last Hour** - Throughput indicator
- **Workers** - Active worker count

### Recent Jobs

Live-updating table of the 20 most recent jobs with:

- Job class name
- Queue
- Status badge
- Timestamp

### Active Workers

Grid of currently running workers showing:

- Worker ID (truncated)
- Status (running/paused)
- Queue assignment
- PID

---

## Jobs Page

### Filtering Jobs

Use the filter bar to narrow results:

| Filter | Options |
|--------|---------|
| Status | All, Pending, Processing, Completed, Failed |
| Queue | Dynamic list from your jobs |
| Search | Job class name |

### Job Detail

Click "View" on any job to see:

- **Overview** - Job ID, class, queue, connection, attempts, worker
- **Timestamps** - Queued, started, completed, duration
- **Payload** - Serialized job data (JSON formatted)
- **Exception** - Stack trace for failed jobs

---

## Workers Page

### Starting Workers

1. Click **"+ Start Worker"**
2. Select queue from dropdown
3. Click **"Start Worker"**

The worker starts with your supervisor's default configuration.

### Worker Controls

Each worker card has action buttons:

| Button | Action |
|--------|--------|
| **Pause** | Finish current job, then wait |
| **Resume** | Continue processing (from paused) |
| **Stop** | Finish current job, then exit |

> **Note:** Commands take 1-3 seconds to take effect (polling interval).

### Worker Status

| Status | Meaning |
|--------|---------|
| 🟢 Running | Actively processing jobs |
| 🟡 Paused | Waiting for resume command |
| ⚫ Stopped | Process has exited |

### Health Indicator

Workers show their last heartbeat time. Healthy workers update every 3 seconds.

---

## Failed Jobs Page

### Viewing Exceptions

Click **"View"** to see the full exception trace in a modal:

- Exception class and message
- File and line number
- Full stack trace

### Retrying Jobs

Click **"Retry"** to re-queue a failed job:

- Status resets to "pending"
- Attempts counter increments
- Job re-enters queue for processing

### Deleting Jobs

Click **"Delete"** to permanently remove the job record.

> **Warning:** This only removes the record from Watchtower's tracking. It does not affect Laravel's failed_jobs table if you're also using that.

---

## Metrics Page

### Overview Statistics

Same stats as dashboard plus:

- Total Jobs (all time)
- Completed total
- Failed total
- Last hour counts

### Hourly Throughput Chart

24-hour bar chart showing:

- 🟢 Green bars = Completed jobs
- 🔴 Red bars = Failed jobs

Helps identify:

- Peak processing times
- Failure spikes
- Queue backlogs

### Queue Depths

Cards showing pending job count per queue:

```
default      12 pending
emails       45 pending
reports       3 pending
```

### Average Durations

Table of average processing time per queue:

```
Queue       Average Duration
default     0.45s
emails      1.23s
reports     12.34s
```

---

## Real-Time Updates

The dashboard polls every 3 seconds (configurable) for:

- Job counts
- Recent jobs list
- Worker status

**Live Indicator:**

- 🟢 **Live** - Polling active
- 🔴 **Paused** - Polling stopped

No page refresh needed - data updates automatically.

---

## Mobile Support

The dashboard is fully responsive:

- Sidebar collapses on small screens
- Tables scroll horizontally
- Cards stack vertically
- Touch-friendly controls

---

## Troubleshooting

### Dashboard Not Loading

1. Check authorization gate
2. Verify middleware configuration
3. Check for JavaScript errors in console

### Workers Not Responding to Commands

1. Check `watchtower.command_bus` config -- is it `redis` or `database`?
2. If `redis`: verify Redis connection and `watchtower.redis_connection` config
3. If `database`: verify `watchtower_commands` table exists (run migrations)
4. Wait for polling interval (1-3s)

### Stats Not Updating

1. Check browser console for poll errors
2. Verify API endpoint accessibility
3. Check for CORS issues if using custom domain

### Missing Jobs

1. Verify queue events are firing
2. Check for exceptions in `JobMonitor`
3. Ensure database connection is correct
