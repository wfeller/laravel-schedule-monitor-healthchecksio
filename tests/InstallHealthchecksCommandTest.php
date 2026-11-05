<?php

namespace WF\ScheduleMonitorHealthchecks\Tests;

use Illuminate\Filesystem\Filesystem;

class InstallHealthchecksCommandTest extends TestCase
{
    private string $configDir;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        // Before providers boot, so vendor:publish targets resolve to this directory.
        $this->configDir = sys_get_temp_dir() . '/hc-install-' . uniqid();
        mkdir($this->configDir);
        $app->useConfigPath($this->configDir);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->deleteDirectory($this->configDir);

        parent::tearDown();
    }

    private function configFile(): string
    {
        return $this->configDir . '/schedule-monitor.php';
    }

    public function test_it_publishes_the_upstream_config_and_swaps_the_job(): void
    {
        $this->artisan('healthchecks:install')->assertSuccessful();

        $contents = file_get_contents($this->configFile());
        $this->assertStringContainsString(
            "'ping_oh_dear_job' => \\WF\\ScheduleMonitorHealthchecks\\Jobs\\PingHealthchecksJob::class,",
            $contents,
        );
        $this->assertStringNotContainsString('PingOhDearJob::class', $contents);
        $this->assertSame(
            'WF\\ScheduleMonitorHealthchecks\\Jobs\\PingHealthchecksJob',
            (require $this->configFile())['oh_dear']['ping_oh_dear_job'],
        );
    }

    public function test_it_is_idempotent(): void
    {
        $this->artisan('healthchecks:install')->assertSuccessful();
        $first = file_get_contents($this->configFile());

        $this->artisan('healthchecks:install')
            ->expectsOutputToContain('already uses PingHealthchecksJob')
            ->assertSuccessful();

        $this->assertSame($first, file_get_contents($this->configFile()));
    }

    public function test_it_swaps_a_job_in_an_existing_config_file(): void
    {
        file_put_contents($this->configFile(), <<<'PHP'
            <?php

            return [
                'oh_dear' => [
                    'queue' => 'pings',
                    "ping_oh_dear_job" => null ,
                ],
            ];
            PHP);

        $this->artisan('healthchecks:install')->assertSuccessful();

        $config = require $this->configFile();
        $this->assertSame('pings', $config['oh_dear']['queue']);
        $this->assertSame('WF\\ScheduleMonitorHealthchecks\\Jobs\\PingHealthchecksJob', $config['oh_dear']['ping_oh_dear_job']);
    }

    public function test_it_leaves_a_custom_job_class_alone(): void
    {
        $original = <<<'PHP'
            <?php

            return [
                'oh_dear' => [
                    'ping_oh_dear_job' => \Illuminate\Queue\CallQueuedClosure::class,
                ],
            ];
            PHP;
        file_put_contents($this->configFile(), $original);

        $this->artisan('healthchecks:install')
            ->expectsOutputToContain('custom job')
            ->assertFailed();

        $this->assertSame($original, file_get_contents($this->configFile()));
    }

    public function test_a_mention_in_a_comment_does_not_count_as_installed(): void
    {
        file_put_contents($this->configFile(), <<<'PHP'
            <?php

            return [
                'oh_dear' => [
                    // TODO: switch to PingHealthchecksJob
                    'ping_oh_dear_job' => Spatie\ScheduleMonitor\Jobs\PingOhDearJob::class,
                ],
            ];
            PHP);

        $this->artisan('healthchecks:install')->assertSuccessful();

        $this->assertSame(
            'WF\\ScheduleMonitorHealthchecks\\Jobs\\PingHealthchecksJob',
            (require $this->configFile())['oh_dear']['ping_oh_dear_job'],
        );
    }

    public function test_it_refuses_to_rewrite_an_expression_it_does_not_understand(): void
    {
        $original = <<<'PHP'
            <?php

            return [
                'oh_dear' => [
                    'ping_oh_dear_job' => env('PING_JOB', \Spatie\ScheduleMonitor\Jobs\PingOhDearJob::class),
                ],
            ];
            PHP;
        file_put_contents($this->configFile(), $original);

        $this->artisan('healthchecks:install')
            ->expectsOutputToContain('Set it by hand')
            ->assertFailed();

        $this->assertSame($original, file_get_contents($this->configFile()));
    }
}
