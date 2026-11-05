<?php

namespace WF\ScheduleMonitorHealthchecks\Commands;

use Illuminate\Console\Command;
use Spatie\ScheduleMonitor\Support\Concerns\UsesScheduleMonitoringModels;
use Spatie\ScheduleMonitor\Support\ScheduledTasks\ScheduledTasks;
use Throwable;
use WF\ScheduleMonitorHealthchecks\HealthchecksClient;
use WF\ScheduleMonitorHealthchecks\Jobs\PingHealthchecksJob;

class SyncHealthchecksCommand extends Command
{
    use UsesScheduleMonitoringModels;

    public $signature = 'healthchecks:sync {--keep-old}';

    public $description = 'Sync the schedule with Healthchecks.io';

    public function handle(): int
    {
        $pingJob = config('schedule-monitor.oh_dear.ping_oh_dear_job');

        if (! is_string($pingJob) || ! is_a($pingJob, PingHealthchecksJob::class, true)) {
            $this->error('schedule-monitor.oh_dear.ping_oh_dear_job is not PingHealthchecksJob, so pings would still go to Oh Dear. Run `php artisan healthchecks:install`.');

            return self::FAILURE;
        }

        $apiKey = config('schedule-monitor-healthchecks.api_key');

        if (empty($apiKey)) {
            $this->warn('Healthchecks API key not configured. Set HEALTHCHECKS_API_KEY in your .env file.');

            return self::SUCCESS;
        }

        $tag = config('schedule-monitor-healthchecks.tag');

        // Orphan cleanup lists checks by this tag; an empty tag would list (and delete)
        // every check in the project, and a tag with spaces becomes several tags.
        if (! is_string($tag) || ! preg_match('/^\S+$/', $tag)) {
            $this->error('schedule-monitor-healthchecks.tag must be a non-empty string without spaces.');

            return self::FAILURE;
        }

        $client = app(HealthchecksClient::class);

        $monitoredTaskNames = ScheduledTasks::createForSchedule()
            ->monitoredAtOhDear()
            ->map->name()
            ->values()
            ->toArray();

        $tasksToSync = $this->getMonitoredScheduleTaskModel()
            ->whereIn('name', $monitoredTaskNames)
            ->get();

        $this->info("Syncing {$tasksToSync->count()} task(s) with Healthchecks...");

        $errorCount = 0;

        foreach ($tasksToSync as $task) {
            try {
                $params = [
                    'name' => $task->name,
                    'grace' => max(($task->grace_time_in_minutes ?? 5) * 60, 60),
                    'tags' => $tag,
                    'methods' => 'POST',
                    // Match on tags too, so apps sharing a project with the same task
                    // names (e.g. staging and production) don't take over each other's checks.
                    'unique' => ['name', 'tags'],
                ];

                if (! is_null($channels = config('schedule-monitor-healthchecks.channels', '*'))) {
                    $params['channels'] = $channels;
                }

                if ($task->cron_expression) {
                    $params['schedule'] = $task->cron_expression;
                    $params['tz'] = $task->timezone ?: 'UTC';
                } else {
                    $params['timeout'] = 86400;
                }

                $check = $client->upsertCheck($params);

                $task->update(['ping_url' => $this->resolvePingUrl($check)]);
                $task->markAsRegisteredOnOhDear();

                $this->line("  <info>✓</info> {$task->name}");
            } catch (Throwable $e) {
                $this->error("  ✗ {$task->name}: " . $e->getMessage());
                $errorCount++;
            }
        }

        if (! $this->option('keep-old')) {
            $this->deleteOrphanedChecks($client, $tag, $monitoredTaskNames);
        }

        $this->info('Healthchecks sync complete.');

        return $errorCount > 0 ? self::FAILURE : self::SUCCESS;
    }

    protected function resolvePingUrl(array $check): string
    {
        $pingUrlBase = config('schedule-monitor-healthchecks.ping_url');

        if ($pingUrlBase) {
            return rtrim($pingUrlBase, '/') . '/' . $check['uuid'];
        }

        return $check['ping_url'];
    }

    /**
     * Delete tagged checks whose name is no longer in the schedule. Matching on name
     * (not on the UUIDs upserted in this run) keeps a task's check alive when its
     * upsert failed transiently.
     */
    protected function deleteOrphanedChecks(HealthchecksClient $client, string $tag, array $monitoredTaskNames): void
    {
        try {
            $existingChecks = $client->listChecks($tag);
        } catch (Throwable $e) {
            $this->warn('Could not list existing checks for cleanup: ' . $e->getMessage());

            return;
        }

        $orphaned = collect($existingChecks)->reject(
            fn (array $check) => in_array($check['name'], $monitoredTaskNames, true)
        );

        foreach ($orphaned as $check) {
            try {
                $client->deleteCheck($check['uuid']);
                $this->line("  <comment>×</comment> Deleted orphaned check: {$check['name']}");
            } catch (Throwable $e) {
                $this->error("  ✗ Failed to delete {$check['name']}: " . $e->getMessage());
            }
        }
    }
}
