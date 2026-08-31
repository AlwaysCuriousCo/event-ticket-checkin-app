<?php

namespace App\Services\Api;

use App\Models\Site;

/**
 * Client for the companion plugin's `event-ticket-scanner/v1` REST API.
 * The contract lives in docs/api/openapi.yaml — every array shape returned
 * here mirrors that spec verbatim.
 *
 * Implementations: HttpApiClient (real sites) and FixtureApiClient
 * (docs/api/fixtures/, used for all development until Stage 8).
 * Selected via config('ticketscanner.api').
 */
interface ApiClient
{
    /**
     * GET /me — validate credentials and site capabilities.
     *
     * @return array{site_name: string, site_url: string, user: array, capabilities: array{can_checkin: bool}, plugin_version: string, event_tickets_version: string, providers: string[]}
     *
     * @throws ApiException
     */
    public function me(Site $site): array;

    /**
     * GET /events
     *
     * @return array{events: array[], total: int, page: int, per_page: int, has_more: bool}
     *
     * @throws ApiException
     */
    public function events(Site $site, int $page = 1): array;

    /**
     * GET /events/{id}/attendees — full sync when $updatedSince is null.
     *
     * @return array{attendees: array[], total: int, page: int, per_page: int, has_more: bool, server_time: string}
     *
     * @throws ApiException
     */
    public function attendees(Site $site, int $wpEventId, ?string $updatedSince = null, int $page = 1, int $perPage = 100): array;

    /**
     * POST /checkins — batched, idempotent by op_id.
     *
     * @param  array<int, array{op_id: string, attendee_id: int, action: string, occurred_at: string}>  $operations
     * @return array{results: array<int, array{op_id: string, status: string, message?: string, attendee: ?array}>, server_time: string}
     *
     * @throws ApiException
     */
    public function pushCheckins(Site $site, string $deviceId, array $operations): array;

    /**
     * GET /events/{id}/stats
     *
     * @return array{event_id: int, total: int, checked_in: int, by_ticket_type: array[], server_time: string}
     *
     * @throws ApiException
     */
    public function stats(Site $site, int $wpEventId): array;

    /**
     * POST /pair — trade a scanned single-use pairing token for an
     * Application Password. Unauthenticated by design: the token IS the
     * credential, so this takes a bare site URL, not a Site.
     *
     * @return array{site_name: string, site_url: string, username: string, app_password: string}
     *
     * @throws ApiException
     */
    public function pair(string $siteUrl, string $token, string $deviceName): array;
}
