<?php

namespace App\Services\Api;

use App\Models\Site;
use App\Services\SiteCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Talks to a real WordPress site running the companion plugin. */
class HttpApiClient implements ApiClient
{
    public function __construct(private SiteCredentials $credentials) {}

    public function me(Site $site): array
    {
        return $this->get($site, '/me');
    }

    public function events(Site $site, int $page = 1): array
    {
        // upcoming=0: the server hides past events by default, but the app
        // needs them for the "show past events" list and splits them itself.
        return $this->get($site, '/events', ['page' => $page, 'upcoming' => 0]);
    }

    public function attendees(Site $site, int $wpEventId, ?string $updatedSince = null, int $page = 1, int $perPage = 100): array
    {
        return $this->get($site, "/events/{$wpEventId}/attendees", array_filter([
            'updated_since' => $updatedSince,
            'page' => $page,
            'per_page' => $perPage,
        ], fn ($v) => $v !== null));
    }

    public function pushCheckins(Site $site, string $deviceId, array $operations): array
    {
        try {
            $response = $this->request($site)->post($site->apiBase().'/checkins', [
                'device_id' => $deviceId,
                'operations' => $operations,
            ]);
        } catch (ConnectionException $e) {
            throw new ApiException($e->getMessage());
        }

        return $this->decode($response);
    }

    public function stats(Site $site, int $wpEventId): array
    {
        return $this->get($site, "/events/{$wpEventId}/stats");
    }

    private function get(Site $site, string $path, array $query = []): array
    {
        try {
            $response = $this->request($site)->get($site->apiBase().$path, $query);
        } catch (ConnectionException $e) {
            throw new ApiException($e->getMessage());
        }

        return $this->decode($response);
    }

    public function pair(string $siteUrl, string $token, string $deviceName): array
    {
        try {
            $response = $this->baseRequest()->post(Site::apiBaseFor($siteUrl).'/pair', [
                'token' => $token,
                'device_name' => $deviceName,
            ]);
        } catch (ConnectionException $e) {
            throw new ApiException($e->getMessage());
        }

        return $this->decode($response);
    }

    private function request(Site $site): PendingRequest
    {
        $password = $this->credentials->passwordFor($site);

        if ($password === null) {
            throw new ApiException('No stored credentials for this site.', 401);
        }

        return $this->baseRequest()->withBasicAuth($site->username, $password);
    }

    /** Shared transport config; /pair is the one call with no credentials. */
    private function baseRequest(): PendingRequest
    {
        $request = Http::acceptJson()
            ->timeout(20)
            ->connectTimeout(8);

        // Dev-only: trust a private CA (Herd/Valet *.test certs). See config.
        if ($bundle = $this->caBundle()) {
            $request = $request->withOptions(['verify' => $bundle]);
        }

        return $request;
    }

    private function caBundle(): ?string
    {
        $configured = config('ticketscanner.ca_bundle');

        if (! $configured) {
            return null;
        }

        if (is_file($configured)) {
            return $configured;
        }

        // Relative paths: base_path covers files shipped inside the app
        // bundle (resources/…); storage_path kept for backwards compat.
        foreach ([base_path($configured), storage_path($configured)] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function decode(Response $response): array
    {
        $json = $response->json();

        if ($response->failed()) {
            throw new ApiException(
                is_array($json) ? ($json['message'] ?? $response->reason()) : $response->reason(),
                $response->status(),
                is_array($json) ? ($json['code'] ?? null) : null,
            );
        }

        if (! is_array($json)) {
            throw new ApiException('Malformed (non-JSON) response from site.', $response->status());
        }

        return $json;
    }
}
