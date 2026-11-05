<?php

namespace WF\ScheduleMonitorHealthchecks;

use Illuminate\Support\Facades\Http;

class HealthchecksClient
{
    protected string $apiKey;
    protected string $apiUrl;

    public function __construct(string $apiKey, string $apiUrl)
    {
        $this->apiKey = $apiKey;
        $this->apiUrl = rtrim($apiUrl, '/');
    }

    public function listChecks(?string $tag = null): array
    {
        $query = [];
        if ($tag) {
            $query['tag'] = $tag;
        }

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
        ])->timeout(10)->get("{$this->apiUrl}/api/v3/checks/", $query);

        $response->throw();

        return $response->json('checks', []);
    }

    public function upsertCheck(array $params): array
    {
        $params['unique'] = $params['unique'] ?? ['name'];

        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
        ])->timeout(10)->post("{$this->apiUrl}/api/v3/checks/", $params);

        $response->throw();

        return $response->json();
    }

    public function deleteCheck(string $uuid): array
    {
        $response = Http::withHeaders([
            'X-Api-Key' => $this->apiKey,
        ])->timeout(10)->delete("{$this->apiUrl}/api/v3/checks/{$uuid}");

        $response->throw();

        return $response->json();
    }
}
