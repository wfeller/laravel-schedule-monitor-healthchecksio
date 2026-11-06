# laravel-schedule-monitor-healthchecksio

Send [spatie/laravel-schedule-monitor](https://github.com/spatie/laravel-schedule-monitor) pings to [Healthchecks.io](https://healthchecks.io) instead of Oh Dear. Works with the hosted service and with self-hosted instances.

## How it works

When a scheduled task starts, finishes or fails, `spatie/laravel-schedule-monitor` calls `pingOhDear()` on the `MonitoredScheduledTask` model, which dispatches the job class configured in `schedule-monitor.oh_dear.ping_oh_dear_job`. `php artisan healthchecks:install` puts this package's `PingHealthchecksJob` in that config key, so the same log item is sent to Healthchecks instead.

There's no custom model and no migration. The existing `ping_url` column on `monitored_scheduled_tasks` stores the Healthchecks ping URL.

```
Scheduled task event
  → MonitoredScheduledTask::markAsFinished()
    → pingOhDear($logItem)
      → config('schedule-monitor.oh_dear.ping_oh_dear_job')  ← set to PingHealthchecksJob by `healthchecks:install`
        → PingHealthchecksJob
          → POST {ping_url}[/start|/fail]
```

## Requirements

- PHP 8.3+
- Laravel 11, 12 or 13
- `spatie/laravel-schedule-monitor` 4.1+ (the first version where the ping job class is configurable)

## Installation

```bash
composer require wfeller/laravel-schedule-monitor-healthchecksio
```

Then point the schedule monitor at the Healthchecks job:

```bash
php artisan healthchecks:install
```

This publishes `config/schedule-monitor.php` if you haven't already, and sets `'ping_oh_dear_job' => \WF\ScheduleMonitorHealthchecks\Jobs\PingHealthchecksJob::class` in it. Commit the change. If that entry is something other than a plain `Foo::class`, `null` or `''` (an `env()` call, for example), the command leaves the file alone and tells you what to set by hand.

`healthchecks:sync` refuses to run until this is done. Otherwise upstream's `PingOhDearJob` would send its pings to the Healthchecks URLs, where they'd fail.

### Environment

```env
HEALTHCHECKS_API_KEY=your-project-api-key
```

That's enough for the hosted service: the API URL defaults to `https://healthchecks.io`, and each check's ping URL comes from the API response (`https://hc-ping.com/<uuid>`).

For a self-hosted instance:

```env
HEALTHCHECKS_API_KEY=your-project-api-key
HEALTHCHECKS_API_URL=https://hc.example.com
HEALTHCHECKS_PING_URL=https://hc.example.com/ping
```

**Leave `OH_DEAR_MONITOR_ID` unset.** When it's set, `schedule-monitor:sync` registers the tasks at Oh Dear and overwrites `ping_url` with Oh Dear URLs.

Start signals are optional. Turn them on to get execution times and to be alerted when a job runs longer than its grace time:

```env
OH_DEAR_SEND_STARTING_PING=true
```

### Config

Publishing the config is optional:

```bash
php artisan vendor:publish --tag="schedule-monitor-healthchecks-config"
```

| Key | Env | Default | Description |
|---|---|---|---|
| `api_key` | `HEALTHCHECKS_API_KEY` | `''` | Read-write project API key (Project Settings → API Access) |
| `api_url` | `HEALTHCHECKS_API_URL` | `https://healthchecks.io` | Base URL of the Management API |
| `ping_url` | `HEALTHCHECKS_PING_URL` | `''` | Base URL of the Pinging API. Empty: use the `ping_url` the API returns |
| `tag` | `HEALTHCHECKS_TAG` | `laravel-schedule-monitor` | Tag put on every synced check: one word, no spaces. Checks are matched on name + tag, and orphan cleanup only touches checks with this tag |
| `channels` | — | `*` | Integrations assigned on every sync. `*` = all, `''` = none, `null` = don't touch existing assignments |
| `queue` | `HEALTHCHECKS_QUEUE` | `null` | Queue for `PingHealthchecksJob` |
| `retry_job_for_minutes` | `HEALTHCHECKS_RETRY_JOB_FOR_MINUTES` | `10` | How long the queued job keeps retrying |
| `retry_delay_ms` | `HEALTHCHECKS_RETRY_DELAY_MS` | `10000` | Delay between the 3 in-job HTTP attempts |
| `timeout` | `HEALTHCHECKS_TIMEOUT` | `10` | Ping request timeout in seconds (Management API calls use a fixed 10s) |
| `debug_logging` | `HEALTHCHECKS_DEBUG_LOGGING` | `false` | Log URL and body when a ping fails |
| `silence_in_horizon` | `HEALTHCHECKS_SILENCE_IN_HORIZON` | `true` | Add `PingHealthchecksJob` to Horizon's silenced jobs |

## Usage

### Deploy

```bash
php artisan schedule-monitor:sync   # upstream: store the schedule in the database
php artisan healthchecks:sync       # upsert a check per task, store its ping URL
```

Run both on every deploy, in that order. `healthchecks:sync` upserts a check for each monitored task (matched on name and tag) and writes the ping URL to `monitored_scheduled_tasks.ping_url`. It exits non-zero if any task failed to sync. Without an API key it prints a warning and exits successfully, so local and CI environments don't need one.

Checks that carry the configured tag but whose name is no longer in the schedule are deleted. Pass `--keep-old` to skip that:

```bash
php artisan healthchecks:sync --keep-old
```

If several apps or environments share one Healthchecks project (staging and production, say), give each its own `HEALTHCHECKS_TAG`. Otherwise they share checks for tasks with the same name, and each sync deletes the others' checks. A separate Healthchecks project per app/environment avoids the question entirely.

The sync owns each check's tags, grace time, schedule and (unless `channels` is `null`) integrations, and overwrites them on every run. Don't add tags to synced checks by hand: the check would no longer match on name + tag, and the next sync would create a duplicate.

### Excluding tasks

```php
$schedule->command('reports:generate')->daily()->doNotMonitorAtHealthchecks();
```

`doNotMonitorAtHealthchecks()` is an alias for `doNotMonitorAtOhDear()`; they set the same flag.

### What gets sent

| Task event | Endpoint | Body (`text/plain`) |
|---|---|---|
| Starting | `{ping_url}/start` | empty |
| Finished | `{ping_url}` | runtime, exit code, memory, output |
| Failed | `{ping_url}/fail` | failure message or exit code, runtime, memory, output |

Output is only included for tasks using upstream's `storeOutputInDb()`. Healthchecks stores the first 100 kB of each body.

Pings that reach the job after a newer run's events (queue reordering, retries) are dropped:

- a **start** ping, if the task has since logged a finish or failure. It would leave the check "started" and set off a false alert once the grace time ran out.
- a **success** ping, if the task has since logged a failure, or if start signals are on and a newer run has started without ending yet. It would mask the failure or close the run that is still going. A newer success never drops a late one: under a queue backlog longer than the task's period, that rule would drop every success and mark a healthy check down.

Failure pings are always sent.

A ping that gets a 4xx response other than 408/429 (for example because the check was deleted in Healthchecks) isn't retried: the job fires `HealthchecksPingFailed` and is marked failed. Other failures are retried 3 times inside the job, and the queue then retries the job for `retry_job_for_minutes`.

### Limitations

- Check names are task names, and Healthchecks limits names to 100 characters. Longer names fail to sync and are reported by `healthchecks:sync`.
- No run ID (`rid`) is sent, so Healthchecks pairs a success with the most recent start. That gives the right durations unless runs of the same task overlap.

## Events

| Event | When |
|---|---|
| `WF\ScheduleMonitorHealthchecks\Events\HealthchecksPingFailed` | A ping attempt failed after the in-job HTTP retries. The job is then retried by the queue, except after a permanent 4xx |

```php
use WF\ScheduleMonitorHealthchecks\Events\HealthchecksPingFailed;

Event::listen(function (HealthchecksPingFailed $event) {
    // $event->logItem, $event->exception
});
```

## Testing

```bash
composer test
```

## API references

- [Healthchecks.io Pinging API](https://healthchecks.io/docs/http_api/)
- [Healthchecks.io Management API v3](https://healthchecks.io/docs/api/)

## License

The MIT License (MIT). Please see [LICENSE.md](LICENSE.md) for more information.
