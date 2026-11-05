<?php

namespace WF\ScheduleMonitorHealthchecks\Tests;

use Spatie\ScheduleMonitor\Jobs\PingOhDearJob;

class HealthchecksServiceProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('schedule-monitor.oh_dear.ping_oh_dear_job', PingOhDearJob::class);
    }

    public function test_it_does_not_change_the_configured_ping_job(): void
    {
        $this->assertSame(PingOhDearJob::class, config('schedule-monitor.oh_dear.ping_oh_dear_job'));
    }
}
