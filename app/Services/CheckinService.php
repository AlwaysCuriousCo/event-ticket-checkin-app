<?php

namespace App\Services;

use App\Jobs\SyncEventJob;
use App\Models\Attendee;
use App\Models\CheckinOperation;
use App\Models\Event;
use App\Services\Api\ApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Applies check-in state changes locally (instant, offline-safe) and queues
 * them for server sync. Both the GREEN scan path and manual check-in from the
 * attendee list go through here — one code path, one behavior.
 */
class CheckinService
{
    public function __construct(private DeviceIdentity $device) {}

    public function checkin(Attendee $attendee, Event $event): CheckinOperation
    {
        $attendee->update([
            'checked_in' => true,
            'checked_in_at' => now()->utc()->toIso8601ZuluString(),
            'checked_in_by' => $this->device->id(),
            'checked_in_source' => Attendee::SOURCE_LOCAL,
        ]);

        return $this->enqueue($attendee, $event, CheckinOperation::ACTION_CHECKIN);
    }

    public function undo(Attendee $attendee, Event $event): CheckinOperation
    {
        $attendee->update([
            'checked_in' => false,
            'checked_in_at' => null,
            'checked_in_by' => null,
            'checked_in_source' => Attendee::SOURCE_LOCAL,
        ]);

        return $this->enqueue($attendee, $event, CheckinOperation::ACTION_UNCHECKIN);
    }

    private function enqueue(Attendee $attendee, Event $event, string $action): CheckinOperation
    {
        $operation = CheckinOperation::create([
            'op_id' => (string) Str::uuid(),
            'site_id' => $attendee->site_id,
            'wp_attendee_id' => $attendee->wp_attendee_id,
            'wp_event_id' => $attendee->wp_event_id,
            'action' => $action,
            'occurred_at' => now()->utc()->toIso8601ZuluString(),
        ]);

        $this->dispatchDebouncedSync($event);

        return $operation;
    }

    /**
     * Rapid consecutive scans should produce ONE delayed sync job, not one
     * per scan: Cache::add only succeeds while no debounce window is open.
     *
     * Debounce 0 = sync inline right now (demo / dev); the queued job is
     * kept only as the fallback when that push fails.
     */
    private function dispatchDebouncedSync(Event $event): void
    {
        $seconds = (int) config('ticketscanner.sync_debounce_seconds', 15);

        if ($seconds <= 0) {
            try {
                app(SyncEngine::class)->syncEvent($event);
            } catch (ApiException) {
                SyncEventJob::dispatch($event->id);
            }

            return;
        }

        if (Cache::add("sync-debounce-{$event->id}", true, $seconds)) {
            SyncEventJob::dispatch($event->id)->delay(now()->addSeconds($seconds));
        }
    }
}
