<?php

namespace WF\ScheduleMonitorHealthchecks\Events;

use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;
use Throwable;

class HealthchecksPingFailed
{
    public function __construct(
        public MonitoredScheduledTaskLogItem $logItem,
        public Throwable $exception,
    ) {
    }
}
