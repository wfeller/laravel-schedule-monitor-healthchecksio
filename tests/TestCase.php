<?php

namespace WF\ScheduleMonitorHealthchecks\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\ScheduleMonitor\ScheduleMonitorServiceProvider;
use WF\ScheduleMonitorHealthchecks\HealthchecksServiceProvider;
use WF\ScheduleMonitorHealthchecks\Jobs\PingHealthchecksJob;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            ScheduleMonitorServiceProvider::class,
            HealthchecksServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'sync');
        // What `healthchecks:install` writes to config/schedule-monitor.php.
        $app['config']->set('schedule-monitor.oh_dear.ping_oh_dear_job', PingHealthchecksJob::class);
        $app['config']->set('schedule-monitor-healthchecks.api_key', 'test-key');
        $app['config']->set('schedule-monitor-healthchecks.api_url', 'https://hc.test');
        $app['config']->set('schedule-monitor-healthchecks.retry_delay_ms', 0);
    }

    protected function defineDatabaseMigrations(): void
    {
        include_once __DIR__ . '/../vendor/spatie/laravel-schedule-monitor/database/migrations/create_schedule_monitor_tables.php.stub';

        (new \CreateScheduleMonitorTables())->up();
    }
}
