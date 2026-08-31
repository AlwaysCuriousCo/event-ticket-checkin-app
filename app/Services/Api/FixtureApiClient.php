<?php

namespace App\Services\Api;

use App\Models\Site;

/**
 * Serves the contract fixtures from docs/api/fixtures/ so the whole app can be
 * built and demoed before any WordPress site exists (PLAN.md Stages 2–6).
 *
 * Check-in state is tracked in memory (seeded from the attendee fixture), so a
 * pushed checkin is reflected by later pushes within the same instance —
 * enough statefulness for sync tests without simulating a real server.
 */
class FixtureApiClient implements ApiClient
{
    /** @var array<int, array> attendee fixture rows keyed by attendee id */
    private array $attendees = [];

    private ?ApiException $failNext = null;

    public function __construct(private ?string $fixtureDir = null)
    {
        $this->fixtureDir = $fixtureDir ?? base_path('docs/api/fixtures');

        foreach ($this->load('attendees.json')['attendees'] as $row) {
            $this->attendees[$row['id']] = $row;
        }
    }

    /** Make the next API call throw (retry/backoff tests). */
    public function failNextWith(ApiException $e): void
    {
        $this->failNext = $e;
    }

    public function me(Site $site): array
    {
        $this->maybeFail();

        return $this->load('me.json');
    }

    public function events(Site $site, int $page = 1): array
    {
        $this->maybeFail();

        return $this->load('events.json');
    }

    public function attendees(Site $site, int $wpEventId, ?string $updatedSince = null, int $page = 1, int $perPage = 100): array
    {
        $this->maybeFail();

        $rows = array_values(array_filter(
            $this->attendees,
            fn (array $a) => $a['event_id'] === $wpEventId
                && ($updatedSince === null || $a['updated_at'] > $updatedSince)
        ));

        $pageRows = array_slice($rows, ($page - 1) * $perPage, $perPage);

        return [
            'attendees' => $pageRows,
            'total' => count($rows),
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $page * $perPage < count($rows),
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    public function pushCheckins(Site $site, string $deviceId, array $operations): array
    {
        $this->maybeFail();

        $serverTime = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $results = [];

        foreach ($operations as $op) {
            $results[] = $this->applyOperation($op, $deviceId, $serverTime);
        }

        return ['results' => $results, 'server_time' => $serverTime];
    }

    public function stats(Site $site, int $wpEventId): array
    {
        $this->maybeFail();

        $ofEvent = array_filter($this->attendees, fn (array $a) => $a['event_id'] === $wpEventId);
        $byType = [];

        foreach ($ofEvent as $a) {
            $byType[$a['ticket_id']] ??= ['ticket_id' => $a['ticket_id'], 'name' => $a['ticket_name'], 'total' => 0, 'checked_in' => 0];
            $byType[$a['ticket_id']]['total']++;
            $byType[$a['ticket_id']]['checked_in'] += $a['checked_in'] ? 1 : 0;
        }

        return [
            'event_id' => $wpEventId,
            'total' => count($ofEvent),
            'checked_in' => count(array_filter($ofEvent, fn (array $a) => $a['checked_in'])),
            'by_ticket_type' => array_values($byType),
            'server_time' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }

    private function applyOperation(array $op, string $deviceId, string $serverTime): array
    {
        $attendee = $this->attendees[$op['attendee_id']] ?? null;

        if (! $attendee) {
            return [
                'op_id' => $op['op_id'],
                'status' => 'not_found',
                'message' => "No attendee with ID {$op['attendee_id']}.",
                'attendee' => null,
            ];
        }

        if ($op['action'] === 'checkin') {
            if ($attendee['order_status'] !== 'completed') {
                return [
                    'op_id' => $op['op_id'],
                    'status' => 'not_authorized',
                    'message' => "Order status is {$attendee['order_status']}; attendee is not eligible for check-in.",
                    'attendee' => $attendee,
                ];
            }

            if ($attendee['checked_in']) {
                return [
                    'op_id' => $op['op_id'],
                    'status' => 'already_checked_in',
                    'message' => "Checked in at {$attendee['checked_in_at']} by {$attendee['checked_in_by']}.",
                    'attendee' => $attendee,
                ];
            }

            $attendee = array_merge($attendee, [
                'checked_in' => true,
                'checked_in_at' => $op['occurred_at'],
                'checked_in_by' => $deviceId,
                'updated_at' => $serverTime,
            ]);
        } else {
            if (! $attendee['checked_in']) {
                return [
                    'op_id' => $op['op_id'],
                    'status' => 'not_checked_in',
                    'message' => 'Attendee was not checked in.',
                    'attendee' => $attendee,
                ];
            }

            $attendee = array_merge($attendee, [
                'checked_in' => false,
                'checked_in_at' => null,
                'checked_in_by' => null,
                'updated_at' => $serverTime,
            ]);
        }

        $this->attendees[$attendee['id']] = $attendee;

        return ['op_id' => $op['op_id'], 'status' => 'ok', 'attendee' => $attendee];
    }

    public function pair(string $siteUrl, string $token, string $deviceName): array
    {
        $this->maybeFail();

        return $this->load('pair.json');
    }

    public function tickets(Site $site, int $wpEventId): array
    {
        $this->maybeFail();

        return $this->load('tickets.json');
    }

    public function registerWalkUp(Site $site, int $wpEventId, array $payload): array
    {
        $this->maybeFail();

        $serverTime = now()->utc()->format('Y-m-d\TH:i:s.u\Z');
        $ticket = collect($this->load('tickets.json')['tickets'])->firstWhere('id', $payload['ticket_id']);

        if (! $ticket) {
            throw new ApiException('Ticket not found.', 404, 'event_ticket_scanner_ticket_not_found');
        }

        $checkIn = $payload['check_in'] ?? true;

        $attendee = [
            'id' => max(array_keys($this->attendees)) + 1,
            'event_id' => $wpEventId,
            'ticket_id' => $ticket['id'],
            'ticket_name' => $ticket['name'],
            'provider' => $ticket['provider'],
            'holder_name' => $payload['name'],
            'holder_email' => $payload['email'] ?? '',
            'security_code' => substr(md5('fixture-walkup-'.count($this->attendees)), 0, 8),
            'order_id' => $ticket['provider'] === 'rsvp' ? null : 8100 + count($this->attendees),
            'order_status' => 'completed',
            'checked_in' => $checkIn,
            'checked_in_at' => $checkIn ? $serverTime : null,
            'checked_in_by' => $checkIn ? ($payload['device_id'] ?? '') : null,
            'updated_at' => $serverTime,
        ];

        $this->attendees[$attendee['id']] = $attendee;

        return ['attendee' => $attendee, 'server_time' => $serverTime];
    }

    private function maybeFail(): void
    {
        if ($this->failNext) {
            $e = $this->failNext;
            $this->failNext = null;

            throw $e;
        }
    }

    private function load(string $file): array
    {
        return json_decode(file_get_contents($this->fixtureDir.'/'.$file), true);
    }
}
