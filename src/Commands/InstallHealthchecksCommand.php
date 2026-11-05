<?php

namespace WF\ScheduleMonitorHealthchecks\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Spatie\ScheduleMonitor\Jobs\PingOhDearJob;
use WF\ScheduleMonitorHealthchecks\Jobs\PingHealthchecksJob;

class InstallHealthchecksCommand extends Command
{
    public $signature = 'healthchecks:install';

    public $description = 'Point laravel-schedule-monitor at the Healthchecks ping job in config/schedule-monitor.php';

    public function handle(Filesystem $files): int
    {
        $configPath = config_path('schedule-monitor.php');

        if (! $files->exists($configPath)) {
            $this->call('vendor:publish', ['--tag' => 'schedule-monitor-config']);
        }

        if (! $files->exists($configPath)) {
            $this->error("Could not publish {$configPath}. Publish the laravel-schedule-monitor config and run this command again.");

            return self::FAILURE;
        }

        $jobClass = '\\' . PingHealthchecksJob::class . '::class';
        $current = (require $configPath)['oh_dear']['ping_oh_dear_job'] ?? null;

        if (is_string($current) && is_a($current, PingHealthchecksJob::class, true)) {
            $this->info('config/schedule-monitor.php already uses PingHealthchecksJob.');

            return self::SUCCESS;
        }

        if (! in_array($current, [null, '', PingOhDearJob::class], true)) {
            return $this->askForManualEdit($jobClass, "'ping_oh_dear_job' is set to a custom job ({$current}), so it was left alone.");
        }

        $updated = preg_replace(
            "/(['\"]ping_oh_dear_job['\"]\s*=>\s*)(?:\\\\?(?:Spatie\\\\ScheduleMonitor\\\\Jobs\\\\)?PingOhDearJob::class|null|''|\"\")\s*,/",
            '${1}' . addcslashes($jobClass, '\\$') . ',',
            $files->get($configPath),
            1,
            $count,
        );

        if ($count !== 1) {
            return $this->askForManualEdit($jobClass, "Could not find a plain 'ping_oh_dear_job' entry in config/schedule-monitor.php.");
        }

        $files->put($configPath, $updated);

        $this->info("Set 'oh_dear.ping_oh_dear_job' to {$jobClass} in config/schedule-monitor.php. Commit this change.");
        $this->line('Leave OH_DEAR_MONITOR_ID unset, and set HEALTHCHECKS_API_KEY.');

        return self::SUCCESS;
    }

    protected function askForManualEdit(string $jobClass, string $reason): int
    {
        $this->error($reason . ' Set it by hand:');
        $this->line("    'ping_oh_dear_job' => {$jobClass},");

        return self::FAILURE;
    }
}
