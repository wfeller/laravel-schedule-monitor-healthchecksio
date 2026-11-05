<?php

return [
    /*
     * A project API key (read-write), from the Healthchecks project settings page.
     */
    'api_key' => env('HEALTHCHECKS_API_KEY', ''),

    /*
     * Base URL of the Healthchecks Management API.
     */
    'api_url' => env('HEALTHCHECKS_API_URL', 'https://healthchecks.io'),

    /*
     * Base URL of the Healthchecks Pinging API. When empty, the `ping_url`
     * returned by the Management API is used as-is.
     */
    'ping_url' => env('HEALTHCHECKS_PING_URL', ''),

    /*
     * Tag added to every check created by `healthchecks:sync`. Orphan cleanup
     * only deletes checks carrying this tag, so give each app sharing a
     * Healthchecks project its own tag.
     */
    'tag' => env('HEALTHCHECKS_TAG', 'laravel-schedule-monitor'),

    /*
     * Integrations assigned to each check on every sync. `*` assigns all of the
     * project's integrations, `''` assigns none. Set to null to leave the
     * integrations of existing checks untouched.
     */
    'channels' => '*',

    /*
     * The queue `PingHealthchecksJob` is dispatched on.
     */
    'queue' => env('HEALTHCHECKS_QUEUE'),

    'retry_job_for_minutes' => env('HEALTHCHECKS_RETRY_JOB_FOR_MINUTES', 10),

    'retry_delay_ms' => env('HEALTHCHECKS_RETRY_DELAY_MS', 10_000),

    'timeout' => env('HEALTHCHECKS_TIMEOUT', 10),

    'debug_logging' => env('HEALTHCHECKS_DEBUG_LOGGING', false),

    'silence_in_horizon' => env('HEALTHCHECKS_SILENCE_IN_HORIZON', true),
];
