<?php

namespace WF\ScheduleMonitorHealthchecks\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;
use WF\ScheduleMonitorHealthchecks\Events\HealthchecksPingFailed;
use WF\ScheduleMonitorHealthchecks\Jobs\PingHealthchecksJob;

class PingHealthchecksJobTest extends TestCase
{
    private const PING_URL = 'https://hc-ping.test/0f0f0f0f-1111-2222-3333-444444444444';

    private function task(?string $pingUrl = self::PING_URL): MonitoredScheduledTask
    {
        return MonitoredScheduledTask::create([
            'name' => 'inspire',
            'type' => 'command',
            'cron_expression' => '0 0 * * *',
            'timezone' => 'UTC',
            'ping_url' => $pingUrl,
            'grace_time_in_minutes' => 5,
        ]);
    }

    private function logItem(MonitoredScheduledTask $task, string $type, array $meta = []): MonitoredScheduledTaskLogItem
    {
        return $task->createLogItem($type)->updateMeta($meta);
    }

    public function test_upstream_ping_dispatches_this_job(): void
    {
        Http::fake();
        $task = $this->task();

        $task->pingOhDear($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED));

        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL);
        $this->assertNotNull($task->fresh()->last_pinged_at);
    }

    public function test_starting_pings_the_start_endpoint_without_body(): void
    {
        Http::fake();
        $task = $this->task();

        (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_STARTING, ['memory' => 1024])))->handle();

        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === self::PING_URL . '/start'
            && $request->body() === '');
    }

    public function test_finished_pings_the_success_endpoint_with_diagnostics(): void
    {
        Http::fake();
        $task = $this->task();

        (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED, [
            'runtime' => 1.5,
            'exit_code' => 0,
            'memory' => 2048,
            'output' => 'all good',
        ])))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL
            && $request->hasHeader('Content-Type', 'text/plain')
            && $request->body() === "Runtime: 1.5\nExit code: 0\nMemory: 2048\nOutput:\nall good");
    }

    public function test_failed_pings_the_fail_endpoint_with_failure_details(): void
    {
        Http::fake();
        $task = $this->task();

        (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FAILED, [
            'failure_message' => 'boom',
        ])))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL . '/fail'
            && $request->body() === 'Failure: boom');
    }

    public function test_task_without_ping_url_is_not_pinged(): void
    {
        Http::fake();
        $task = $this->task(null);

        (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED)))->handle();

        Http::assertNothingSent();
    }

    public function test_starting_ping_is_dropped_when_the_run_already_ended(): void
    {
        Http::fake();
        $task = $this->task();
        $starting = $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_STARTING);
        $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED);

        (new PingHealthchecksJob($starting))->handle();

        Http::assertNothingSent();
    }

    public function test_starting_ping_is_sent_when_only_older_runs_ended(): void
    {
        Http::fake();
        $task = $this->task();
        $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FAILED);
        $starting = $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_STARTING);

        (new PingHealthchecksJob($starting))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL . '/start');
    }

    public function test_failed_exit_code_body_includes_run_details(): void
    {
        Http::fake();
        $task = $this->task();

        (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FAILED, [
            'runtime' => 2.5,
            'exit_code' => 2,
            'memory' => 4096,
            'output' => 'oops',
        ])))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL . '/fail'
            && $request->body() === "Exit code: 2\nRuntime: 2.5\nMemory: 4096\nOutput:\noops");
    }

    public function test_late_success_ping_is_dropped_when_a_newer_run_failed(): void
    {
        Http::fake();
        $task = $this->task();
        $finished = $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED);
        $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FAILED);

        (new PingHealthchecksJob($finished))->handle();

        Http::assertNothingSent();
    }

    public function test_late_success_ping_is_dropped_when_a_newer_run_started(): void
    {
        Http::fake();
        $task = $this->task();
        $finished = $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED);
        $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_STARTING);

        (new PingHealthchecksJob($finished))->handle();

        Http::assertNothingSent();
    }

    public function test_late_failure_ping_is_still_sent(): void
    {
        Http::fake();
        $task = $this->task();
        $failed = $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FAILED, ['failure_message' => 'boom']);
        $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_STARTING);
        $this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED);

        (new PingHealthchecksJob($failed))->handle();

        Http::assertSent(fn (Request $request) => $request->url() === self::PING_URL . '/fail');
    }

    public function test_client_error_is_not_retried_and_not_rethrown(): void
    {
        Http::fake(['*' => Http::response('not found', 404)]);
        Event::fake([HealthchecksPingFailed::class]);
        $task = $this->task();

        (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED)))->handle();

        Http::assertSentCount(1);
        Event::assertDispatched(HealthchecksPingFailed::class);
    }

    public function test_failed_ping_fires_event_and_rethrows(): void
    {
        Http::fake(['*' => Http::response('nope', 500)]);
        Event::fake([HealthchecksPingFailed::class]);
        $task = $this->task();

        try {
            (new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED)))->handle();
            $this->fail('Expected the ping failure to be rethrown.');
        } catch (RequestException) {
        }

        Http::assertSentCount(3);
        Event::assertDispatched(HealthchecksPingFailed::class);
        $this->assertNull($task->fresh()->last_pinged_at);
    }

    public function test_job_uses_configured_queue(): void
    {
        config()->set('schedule-monitor-healthchecks.queue', 'pings');
        $task = $this->task();

        $job = new PingHealthchecksJob($this->logItem($task, MonitoredScheduledTaskLogItem::TYPE_FINISHED));

        $this->assertSame('pings', $job->queue);
    }
}
