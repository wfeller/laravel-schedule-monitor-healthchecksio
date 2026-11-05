<?php

namespace WF\ScheduleMonitorHealthchecks;

use Illuminate\Console\Scheduling\Event as SchedulerEvent;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;
use WF\ScheduleMonitorHealthchecks\Commands\InstallHealthchecksCommand;
use WF\ScheduleMonitorHealthchecks\Commands\SyncHealthchecksCommand;
use WF\ScheduleMonitorHealthchecks\Jobs\PingHealthchecksJob;

class HealthchecksServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__ . '/../config/schedule-monitor-healthchecks.php',
            'schedule-monitor-healthchecks',
        );

        $this->app->bind(HealthchecksClient::class, function () {
            return new HealthchecksClient(
                config('schedule-monitor-healthchecks.api_key', ''),
                config('schedule-monitor-healthchecks.api_url', 'https://healthchecks.io'),
            );
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/schedule-monitor-healthchecks.php' => config_path('schedule-monitor-healthchecks.php'),
            ], 'schedule-monitor-healthchecks-config');

            $this->commands([
                InstallHealthchecksCommand::class,
                SyncHealthchecksCommand::class,
            ]);
        }

        SchedulerEvent::macro('doNotMonitorAtHealthchecks', function (bool $bool = true) {
            $this->doNotMonitorAtOhDear($bool);

            return $this;
        });

        if (config('schedule-monitor-healthchecks.silence_in_horizon', true) && class_exists(Horizon::class)) {
            $silencedJobs = config('horizon.silenced', []);

            if (! in_array(PingHealthchecksJob::class, $silencedJobs)) {
                $silencedJobs[] = PingHealthchecksJob::class;
                config()->set('horizon.silenced', $silencedJobs);
            }
        }
    }
}
