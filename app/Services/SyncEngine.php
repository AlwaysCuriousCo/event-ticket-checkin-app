<?php

namespace App\Services;

use App\Models\Attendee;
use App\Models\CheckinOperation;
use App\Models\Event;
use App\Models\Site;
use App\Services\Api\ApiClient;

/**
 * Offline-first sync (PLAN.md Stage 2): pushes queued check-in operations,
 * then pulls attendee changes. Pull uses the server-echoed `server_time`
 * as the delta cursor — never the device clock.
 */
class SyncEngine
{
    public function __construct(
        private ApiClient $api,
        private Connectivity $connectivity,
        private DeviceIdentity $device,
    ) {}

    /**
     * Full cycle for one event. Push first so this device's check-ins are
     * reflected in the pulled state. No-op when offline.
     */
    public function syncEvent(Event $event): bool
    {
        if (! $this->connectivity->isOnline()) {
            return false;
        }

        $this->pushCheckins($event->site);
        $this->pullAttendees($event);

        return true;
    }

    /**
     * Push all pending operations for the site in batches. Throws ApiException
     * on transport/server failure (callers — the queued job — handle retry).
     *
     * @return int operations confirmed by the server
     */
    public function pushCheckins(Site $site): int
    {
        $confirmed = 0;

        while (true) {
            $batch = CheckinOperation::query()
                ->where('site_id', $site->id)
                ->pending()
                ->orderBy('id')
                ->limit(50)
                ->get();

            if ($batch->isEmpty()) {
                return $confirmed;
            }

            $batch->toQuery()->increment('attempts');

            $response = $this->api->pushCheckins(
                $site,
                $this->device->id(),
                $batch->map(fn (CheckinOperation $op) => [
                    'op_id' => $op->op_id,
                    'attendee_id' => $op->wp_attendee_id,
                    'action' => $op->action,
                    'occurred_at' => $op->occurred_at,
                ])->all(),
            );

            $byOpId = $batch->keyBy('op_id');

            foreach ($response['results'] as $result) {
                $operation = $byOpId->get($result['op_id']);

                if (! $operation) {
                    continue;
                }

                $operation->update([
                    'synced_at' => now(),
                    'result_status' => $result['status'],
                ]);

                // Whatever the outcome, the server's attendee state is now
                // the truth — including already_checked_in conflicts, where
                // this overwrites our checked_in_by with the winner's.
                if (is_array($result['attendee'] ?? null)) {
                    $this->applyServerAttendee($site, $result['attendee']);
                }

                $confirmed++;
            }
        }
    }

    /**
     * Pull attendee changes (delta when a cursor exists, else full sync).
     *
     * @return int attendee rows upserted
     */
    public function pullAttendees(Event $event): int
    {
        $cursor = $event->sync_cursor;
        $page = 1;
        $upserted = 0;
        $serverTime = null;

        do {
            $response = $this->api->attendees(
                $event->site,
                $event->wp_event_id,
                $cursor,
                $page,
                (int) config('ticketscanner.sync_per_page', 100),
            );

            $serverTime = $response['server_time'];

            foreach ($response['attendees'] as $row) {
                $upserted += $this->applyServerAttendee($event->site, $row) ? 1 : 0;
            }

            $page++;
        } while ($response['has_more']);

        $event->update(['sync_cursor' => $serverTime, 'last_synced_at' => now()]);

        return $upserted;
    }

    /**
     * Upsert one attendee from server data. Server state wins EXCEPT when this
     * device has an unsynced operation for the attendee — then the local
     * check-in fields are authoritative until that operation is pushed.
     *
     * Public so walk-up registration can upsert the attendee it just
     * created server-side without waiting for the next delta pull.
     */
    public function applyServerAttendee(Site $site, array $row): bool
    {
        $serverFields = [
            'wp_event_id' => $row['event_id'],
            'wp_ticket_id' => $row['ticket_id'],
            'ticket_name' => $row['ticket_name'],
            'provider' => $row['provider'],
            'holder_name' => $row['holder_name'],
            'holder_email' => $row['holder_email'],
            'security_code' => $row['security_code'],
            'wp_order_id' => $row['order_id'] ?? null,
            'order_status' => $row['order_status'],
            'remote_updated_at' => $row['updated_at'],
        ];

        $checkinFields = [
            'checked_in' => (bool) $row['checked_in'],
            'checked_in_at' => $row['checked_in_at'],
            'checked_in_by' => $row['checked_in_by'],
            'checked_in_source' => Attendee::SOURCE_SERVER,
        ];

        $hasPendingOp = CheckinOperation::query()
            ->where('site_id', $site->id)
            ->where('wp_attendee_id', $row['id'])
            ->pending()
            ->exists();

        Attendee::updateOrCreate(
            ['site_id' => $site->id, 'wp_attendee_id' => $row['id']],
            $hasPendingOp ? $serverFields : $serverFields + $checkinFields,
        );

        return true;
    }
}
