<?php

namespace WF\ScheduleMonitorHealthchecks\Tests;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTask;
use Symfony\Component\Console\Output\BufferedOutput;

use function Termwind\renderUsing;

class SyncHealthchecksCommandTest extends TestCase
{
    private array $created = [];

    protected function setUp(): void
    {
        parent::setUp();

        // The upstream sync command renders through Termwind straight to stdout.
        renderUsing(new BufferedOutput());

        $schedule = $this->app->make(Schedule::class);
        $schedule->command('inspire')->dailyAt('03:00')->timezone('Europe/Paris');
        $schedule->command('cache:clear')->hourly()->graceTimeInMinutes(20);
        $schedule->command('view:clear')->daily()->doNotMonitorAtHealthchecks();

        $this->artisan('schedule-monitor:sync')->assertSuccessful();
    }

    private function fakeApi(array $existingChecks = [], array $failingNames = []): void
    {
        Http::fake(function (Request $request) use ($existingChecks, $failingNames) {
            if ($request->method() === 'POST') {
                if (in_array($request['name'], $failingNames, true)) {
                    return Http::response(['error' => 'boom'], 500);
                }

                $uuid = 'uuid-' . md5($request['name']);
                $this->created[$request['name']] = $request->data();

                return Http::response(['uuid' => $uuid, 'ping_url' => "https://hc-ping.com/{$uuid}"], 201);
            }

            if ($request->method() === 'GET') {
                return Http::response(['checks' => $existingChecks]);
            }

            return Http::response([]);
        });
    }

    public function test_it_upserts_monitored_tasks_and_stores_ping_urls(): void
    {
        $this->fakeApi();

        $this->artisan('healthchecks:sync')->assertSuccessful();

        $this->assertSame(['inspire', 'cache:clear'], array_keys($this->created));
        $this->assertSame([
            'name' => 'inspire',
            'grace' => 300,
            'tags' => 'laravel-schedule-monitor',
            'methods' => 'POST',
            'unique' => ['name', 'tags'],
            'channels' => '*',
            'schedule' => '0 3 * * *',
            'tz' => 'Europe/Paris',
        ], $this->created['inspire']);
        $this->assertSame(1200, $this->created['cache:clear']['grace']);

        Http::assertSent(fn (Request $request) => $request->hasHeader('X-Api-Key', 'test-key')
            && str_starts_with($request->url(), 'https://hc.test/api/v3/checks/'));

        $inspire = MonitoredScheduledTask::findByName('inspire');
        $this->assertSame('https://hc-ping.com/uuid-' . md5('inspire'), $inspire->ping_url);
        $this->assertNotNull($inspire->registered_on_oh_dear_at);
        $this->assertNull(MonitoredScheduledTask::findByName('view:clear')->ping_url);
    }

    public function test_ping_url_base_overrides_the_api_ping_url(): void
    {
        config()->set('schedule-monitor-healthchecks.ping_url', 'https://hc.example.com/ping/');
        $this->fakeApi();

        $this->artisan('healthchecks:sync')->assertSuccessful();

        $this->assertSame(
            'https://hc.example.com/ping/uuid-' . md5('inspire'),
            MonitoredScheduledTask::findByName('inspire')->ping_url,
        );
    }

    public function test_tag_and_channels_are_configurable(): void
    {
        config()->set('schedule-monitor-healthchecks.tag', 'app-one');
        config()->set('schedule-monitor-healthchecks.channels', null);
        $this->fakeApi();

        $this->artisan('healthchecks:sync')->assertSuccessful();

        $this->assertSame('app-one', $this->created['inspire']['tags']);
        $this->assertArrayNotHasKey('channels', $this->created['inspire']);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://hc.test/api/v3/checks/?tag=app-one');
    }

    public function test_it_deletes_orphaned_checks_only(): void
    {
        $this->fakeApi([
            ['uuid' => 'uuid-' . md5('inspire'), 'name' => 'inspire'],
            ['uuid' => 'gone-uuid', 'name' => 'removed:command'],
        ]);

        $this->artisan('healthchecks:sync')->assertSuccessful();

        Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
            && $request->url() === 'https://hc.test/api/v3/checks/gone-uuid');
        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE'
            && str_contains($request->url(), md5('inspire')));
    }

    public function test_a_failed_upsert_does_not_delete_that_tasks_check(): void
    {
        $this->fakeApi([
            ['uuid' => 'existing-inspire-uuid', 'name' => 'inspire'],
        ], failingNames: ['inspire']);

        $this->artisan('healthchecks:sync')->assertFailed();

        Http::assertNotSent(fn (Request $request) => $request->method() === 'DELETE');
    }

    public function test_keep_old_skips_cleanup(): void
    {
        $this->fakeApi([['uuid' => 'gone-uuid', 'name' => 'removed:command']]);

        $this->artisan('healthchecks:sync --keep-old')->assertSuccessful();

        Http::assertNotSent(fn (Request $request) => in_array($request->method(), ['GET', 'DELETE'], true));
    }

    public function test_it_refuses_to_sync_while_the_oh_dear_job_is_configured(): void
    {
        config()->set('schedule-monitor.oh_dear.ping_oh_dear_job', \Spatie\ScheduleMonitor\Jobs\PingOhDearJob::class);
        Http::fake();

        $this->artisan('healthchecks:sync')
            ->expectsOutputToContain('healthchecks:install')
            ->assertFailed();

        Http::assertNothingSent();
        $this->assertNull(MonitoredScheduledTask::findByName('inspire')->ping_url);
    }

    public function test_it_refuses_an_empty_or_multi_word_tag(): void
    {
        Http::fake();

        foreach (['', 'my app', null] as $tag) {
            config()->set('schedule-monitor-healthchecks.tag', $tag);

            $this->artisan('healthchecks:sync')
                ->expectsOutputToContain('non-empty string without spaces')
                ->assertFailed();
        }

        Http::assertNothingSent();
    }

    public function test_missing_api_key_skips_sync(): void
    {
        config()->set('schedule-monitor-healthchecks.api_key', '');
        Http::fake();

        $this->artisan('healthchecks:sync')->assertSuccessful();

        Http::assertNothingSent();
    }
}
