<?php

namespace WF\ScheduleMonitorHealthchecks\Jobs;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;
use WF\ScheduleMonitorHealthchecks\Events\HealthchecksPingFailed;
use Throwable;

class PingHealthchecksJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public bool $deleteWhenMissingModels = true;

    public MonitoredScheduledTaskLogItem $logItem;

    public function __construct(MonitoredScheduledTaskLogItem $logItem)
    {
        $this->logItem = $logItem;

        if ($queue = config('schedule-monitor-healthchecks.queue')) {
            $this->onQueue($queue);
        }
    }

    public function handle(): void
    {
        $task = $this->logItem->monitoredScheduledTask;

        if (! $task || empty($task->ping_url)) {
            return;
        }

        if ($this->isSuperseded()) {
            return;
        }

        $endpoint = $this->resolveEndpoint($task->ping_url);
        $body = $this->resolveBody();

        try {
            $request = Http::retry(
                times: 3,
                sleepMilliseconds: config('schedule-monitor-healthchecks.retry_delay_ms', 10_000),
                when: fn (Throwable $exception) => ! $this->isPermanentFailure($exception),
            )->withOptions([
                'timeout' => config('schedule-monitor-healthchecks.timeout', 10),
            ])->withBody($body, 'text/plain');

            $response = $request->post($endpoint);
            $response->throw();
        } catch (Throwable $exception) {
            event(new HealthchecksPingFailed($this->logItem, $exception));

            if (config('schedule-monitor-healthchecks.debug_logging', false)) {
                Log::warning('PingHealthchecksJob failed: ' . $exception->getMessage(), [
                    'url' => $endpoint,
                    'body' => $body,
                    'exception' => $exception->getMessage(),
                ]);
            }

            if ($this->isPermanentFailure($exception)) {
                $this->fail($exception);

                return;
            }

            throw $exception;
        }

        $task->update(['last_pinged_at' => now()]);
    }

    public function retryUntil(): DateTime
    {
        return now()->addMinutes(config('schedule-monitor-healthchecks.retry_job_for_minutes', 10))->toDateTime();
    }

    /**
     * Drop pings that reach the job after a newer run's pings (queue reordering, retries).
     * A late start would leave the check "started" and raise a false alert once the grace
     * time elapses. A late success would mask a newer failure, or close a newer run that
     * is still going. Failures are always sent.
     */
    protected function isSuperseded(): bool
    {
        $supersededBy = match ($this->logItem->type) {
            MonitoredScheduledTaskLogItem::TYPE_STARTING => [
                MonitoredScheduledTaskLogItem::TYPE_FINISHED,
                MonitoredScheduledTaskLogItem::TYPE_FAILED,
            ],
            MonitoredScheduledTaskLogItem::TYPE_FINISHED => [
                MonitoredScheduledTaskLogItem::TYPE_STARTING,
                MonitoredScheduledTaskLogItem::TYPE_FINISHED,
                MonitoredScheduledTaskLogItem::TYPE_FAILED,
            ],
            default => [],
        };

        if ($supersededBy === []) {
            return false;
        }

        return $this->logItem->monitoredScheduledTask->logItems()
            ->where('id', '>', $this->logItem->getKey())
            ->whereIn('type', $supersededBy)
            ->exists();
    }

    /**
     * A 4xx (other than 408/429) means the ping URL itself is wrong, e.g. the check was
     * deleted in Healthchecks. Retrying won't fix it.
     */
    protected function isPermanentFailure(Throwable $exception): bool
    {
        return $exception instanceof RequestException
            && $exception->response->clientError()
            && ! in_array($exception->response->status(), [408, 429], true);
    }

    protected function resolveEndpoint(string $pingUrl): string
    {
        return match ($this->logItem->type) {
            MonitoredScheduledTaskLogItem::TYPE_STARTING => "{$pingUrl}/start",
            MonitoredScheduledTaskLogItem::TYPE_FAILED => "{$pingUrl}/fail",
            default => $pingUrl,
        };
    }

    protected function resolveBody(): string
    {
        $meta = $this->logItem->meta ?? [];

        if ($this->logItem->type === MonitoredScheduledTaskLogItem::TYPE_FINISHED) {
            return $this->formatDiagnosticBody($meta, [
                'runtime' => 'Runtime',
                'exit_code' => 'Exit code',
                'memory' => 'Memory',
            ], 'output');
        }

        if ($this->logItem->type === MonitoredScheduledTaskLogItem::TYPE_FAILED) {
            return $this->formatDiagnosticBody($meta, [
                'failure_message' => 'Failure',
                'exit_code' => 'Exit code',
                'runtime' => 'Runtime',
                'memory' => 'Memory',
            ], 'output');
        }

        return '';
    }

    protected function formatDiagnosticBody(array $meta, array $fields, string $outputKey): string
    {
        $lines = [];

        foreach ($fields as $key => $label) {
            if (isset($meta[$key])) {
                $lines[] = "{$label}: {$meta[$key]}";
            }
        }

        if (isset($meta[$outputKey])) {
            $lines[] = "Output:\n{$meta[$outputKey]}";
        }

        return implode("\n", $lines);
    }
}
