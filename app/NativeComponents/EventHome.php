<?php

namespace App\NativeComponents;

use App\Models\CheckinOperation;
use App\Models\Event;
use App\Services\Api\ApiException;
use App\Services\SyncEngine;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class EventHome extends NativeComponent
{
    public string $title = '';

    public string $date = '';

    public int $total = 0;

    public int $checkedIn = 0;

    public int $pendingOps = 0;

    public string $lastSynced = 'never';

    public bool $syncing = false;

    public string $error = '';

    /** Walk-ups only make sense while the event can still be attended. */
    public bool $canRegisterWalkUp = false;

    protected ?Event $event = null;

    public function mount(): void
    {
        $this->event = Event::find((int) $this->param('event', 0));

        if (! $this->event) {
            $this->replace('/events');

            return;
        }

        $this->title = $this->event->title;
        $this->date = (string) $this->event->starts_at;
        $this->canRegisterWalkUp = $this->event->allow_walkup && ! $this->event->hasEnded();

        // First visit for this event: block on the initial attendee sync so
        // the door list exists before anyone scans (fast against fixtures;
        // Stage 6 moves this to a background job with progress).
        if (! $this->event->last_synced_at) {
            $this->syncNow();
        } else {
            $this->refreshCounts();
        }
    }

    /**
     * Cash/comp walk-ups register in-app via the plugin API; the walk-up
     * screen hands card buyers to the site's own checkout.
     */
    public function registerWalkUp(): void
    {
        if ($this->event) {
            $this->navigate("/events/{$this->event->id}/walkup");
        }
    }

    public function onResume(): void
    {
        if ($this->event) {
            $this->refreshCounts();
        }
    }

    public function syncNow(): void
    {
        $this->error = '';
        $this->syncing = true;

        try {
            app(SyncEngine::class)->syncEvent($this->event);
        } catch (ApiException $e) {
            $this->error = "Sync failed: {$e->getMessage()}";
        }

        $this->syncing = false;
        $this->refreshCounts();
    }

    public function startScanning(): void
    {
        $this->navigate("/scan/{$this->event->id}");
    }

    public function openAttendees(): void
    {
        $this->navigate("/events/{$this->event->id}/attendees");
    }

    public function openStats(): void
    {
        $this->navigate("/events/{$this->event->id}/stats");
    }

    private function refreshCounts(): void
    {
        $this->event->refresh();

        $attendees = $this->event->attendees();
        $this->total = (clone $attendees)->count();
        $this->checkedIn = (clone $attendees)->where('checked_in', true)->count();

        $this->pendingOps = CheckinOperation::query()
            ->where('site_id', $this->event->site_id)
            ->where('wp_event_id', $this->event->wp_event_id)
            ->pending()
            ->count();

        $this->lastSynced = $this->event->last_synced_at?->diffForHumans() ?? 'never';
    }

    public function navTitle(): string
    {
        return $this->title;
    }

    public function render(): View
    {
        return view('native.event-home');
    }
}
