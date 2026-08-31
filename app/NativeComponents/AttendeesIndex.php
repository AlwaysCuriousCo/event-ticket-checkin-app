<?php

namespace App\NativeComponents;

use App\Models\Attendee;
use App\Models\Event;
use App\Services\CheckinService;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Local-first attendee list: search and filters hit SQLite only, so the
 * door list works with zero connectivity.
 */
class AttendeesIndex extends NativeComponent
{
    public string $query = '';

    /** all | in | out */
    public string $filter = 'all';

    /** @var array<int, array{id: int, name: string, email: string, ticket: string, checked_in: bool}> */
    public array $rows = [];

    public int $totalCount = 0;

    public int $checkedInCount = 0;

    protected ?Event $event = null;

    public function mount(): void
    {
        $this->event = Event::find((int) $this->param('event', 0));

        if (! $this->event) {
            $this->replace('/events');

            return;
        }

        $this->loadRows();
    }

    public function onResume(): void
    {
        if ($this->event) {
            $this->loadRows();
        }
    }

    public function updatedQuery(string $value): void
    {
        $this->loadRows();
    }

    public function setFilter(string $filter): void
    {
        $this->filter = in_array($filter, ['all', 'in', 'out'], true) ? $filter : 'all';
        $this->loadRows();
    }

    /** Swipe action: check an attendee in without leaving the list. */
    public function swipeCheckin(int $attendeeId): void
    {
        $attendee = Attendee::query()
            ->where('site_id', $this->event->site_id)
            ->where('wp_event_id', $this->event->wp_event_id)
            ->find($attendeeId);

        if ($attendee && $attendee->isEligibleForCheckin() && ! $attendee->checked_in) {
            app(CheckinService::class)->checkin($attendee, $this->event);
        }

        $this->loadRows();
    }

    public function open(int $attendeeId): void
    {
        $this->navigate("/attendees/{$attendeeId}");
    }

    private function loadRows(): void
    {
        $base = Attendee::query()
            ->where('site_id', $this->event->site_id)
            ->where('wp_event_id', $this->event->wp_event_id);

        $this->totalCount = (clone $base)->count();
        $this->checkedInCount = (clone $base)->where('checked_in', true)->count();

        $this->rows = $base
            ->when($this->filter === 'in', fn ($q) => $q->where('checked_in', true))
            ->when($this->filter === 'out', fn ($q) => $q->where('checked_in', false))
            ->when(trim($this->query) !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->query)).'%';
                $q->where(fn ($w) => $w
                    ->where('holder_name', 'like', $term)
                    ->orWhere('holder_email', 'like', $term));
            })
            ->orderBy('holder_name')
            ->limit(200)
            ->get()
            ->map(fn (Attendee $a) => [
                'id' => $a->id,
                'name' => $a->holder_name,
                'email' => $a->holder_email,
                'ticket' => (string) $a->ticket_name,
                'checked_in' => $a->checked_in,
                'eligible' => $a->isEligibleForCheckin() && ! $a->checked_in,
            ])
            ->all();
    }

    public function navTitle(): string
    {
        return 'Attendees';
    }

    public function render(): View
    {
        return view('native.attendees-index');
    }
}
