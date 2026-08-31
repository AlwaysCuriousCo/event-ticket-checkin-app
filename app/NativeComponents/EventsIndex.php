<?php

namespace App\NativeComponents;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

class EventsIndex extends NativeComponent
{
    /** @var array<int, array{id: int, title: string, date: string, venue: ?string, attendee_count: int, checked_in_count: int}> */
    public array $events = [];

    public string $siteName = '';

    /** @var array<int, array{id: int, title: string, date: string, venue: ?string, attendee_count: int, checked_in_count: int}> */
    public array $pastEvents = [];

    public bool $showPast = false;

    /** Title / venue filter, applied to the local copy only. */
    public string $query = '';

    public string $error = '';

    /**
     * Tab chrome drops display-mode="large" when folding a screen's
     * top-bar (NativeRootTabs has no navDisplayMode), leaving a stubby
     * centered title — so hide the bar and render our own large heading.
     */
    protected bool $hidesNavBar = true;

    protected ?Site $site = null;

    /**
     * Counts from the last successful pull, keyed by wp_event_id. Search
     * rebuilds must reuse these — this screen never syncs attendees, so
     * local rows would zero out fresh server totals.
     *
     * @var array<int, array{0: int, 1: int}>
     */
    protected array $serverCounts = [];

    public function mount(): void
    {
        $this->site = Site::current();

        if (! $this->site) {
            $this->replace('/connect');

            return;
        }

        $this->siteName = $this->site->name;
        $this->loadEvents();
    }

    public function onResume(): void
    {
        // Re-resolve: the user may have switched sites on the Profile tab
        // while this screen sat in the tab stack.
        $previousSiteId = $this->site?->id;
        $this->site = Site::current();

        if (! $this->site) {
            $this->replace('/connect');

            return;
        }

        // wp_event_ids collide across sites — never let one site's counts
        // survive a switch (an offline resume would show them otherwise).
        if ($this->site->id !== $previousSiteId) {
            $this->serverCounts = [];
        }

        $this->siteName = $this->site->name;
        $this->loadEvents();
    }

    /** Re-filter as the search box changes — local only, no server pull. */
    public function updatedQuery(string $value): void
    {
        $this->rebuildLists();
    }

    public function refresh(): void
    {
        $this->loadEvents();
    }

    public function open(int $eventId): void
    {
        $this->navigate("/events/{$eventId}");
    }

    /** Past events are hidden by default — door staff only ever want today's. */
    public function togglePast(): void
    {
        $this->showPast = ! $this->showPast;
    }

    /**
     * Pull the event list from the API and upsert into local storage, so
     * events stay browsable offline. Counts shown come from the server
     * payload when fresh, or the local attendee table when offline.
     */
    private function loadEvents(): void
    {
        $this->error = '';
        $serverCounts = [];

        try {
            // Walk every page before pruning — treating page 1 as the full
            // list would delete events that live on later pages.
            $page = 1;

            do {
                $response = app(ApiClient::class)->events($this->site, $page);

                foreach ($response['events'] as $row) {
                    Event::updateOrCreate(
                        ['site_id' => $this->site->id, 'wp_event_id' => $row['id']],
                        [
                            'title' => $row['title'],
                            'starts_at' => $row['start_date'],
                            'ends_at' => $row['end_date'],
                            'timezone' => $row['timezone'],
                            'venue' => $row['venue'] ?? null,
                            'allow_walkup' => (bool) ($row['allow_walkup'] ?? true),
                        ],
                    );

                    $serverCounts[$row['id']] = [$row['attendee_count'], $row['checked_in_count']];
                }

                $page++;
            } while (($response['has_more'] ?? false) && $page <= 50);

            // The pull is the authoritative full list: drop local events the
            // server no longer has (deleted/unpublished), and their cached
            // attendees — otherwise stale duplicates linger forever.
            $goneEventIds = Event::query()
                ->where('site_id', $this->site->id)
                ->whereNotIn('wp_event_id', array_keys($serverCounts))
                ->pluck('wp_event_id');

            if ($goneEventIds->isNotEmpty()) {
                Attendee::query()
                    ->where('site_id', $this->site->id)
                    ->whereIn('wp_event_id', $goneEventIds)
                    ->delete();
                Event::query()
                    ->where('site_id', $this->site->id)
                    ->whereIn('wp_event_id', $goneEventIds)
                    ->delete();
            }
            $this->serverCounts = $serverCounts;
        } catch (ApiException) {
            $this->error = 'Offline — showing cached events.';
        }

        $this->rebuildLists();
    }

    /** Build the upcoming/past lists from local storage, applying the filter. */
    private function rebuildLists(): void
    {
        $serverCounts = $this->serverCounts;
        $term = trim($this->query);

        $rows = Event::query()
            ->where('site_id', $this->site->id)
            ->when($term !== '', function ($q) use ($term) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

                $q->where(fn ($q) => $q->where('title', 'like', $like)->orWhere('venue', 'like', $like));
            })
            ->orderBy('starts_at')
            ->get()
            ->map(function (Event $event) use ($serverCounts) {
                [$total, $checkedIn] = $serverCounts[$event->wp_event_id] ?? [
                    $event->attendees()->count(),
                    $event->attendees()->where('checked_in', true)->count(),
                ];

                return [
                    'id' => $event->id,
                    'title' => $event->title,
                    'date' => $event->starts_at ?? '',
                    'venue' => $event->venue,
                    'attendee_count' => $total,
                    'checked_in_count' => $checkedIn,
                    'past' => $event->hasEnded(),
                ];
            });

        // Upcoming: soonest first, so tonight's door is at the top.
        // Past: most recently finished first, since that's what staff go
        // back to for a late arrival or a stats check.
        $this->events = $rows->reject(fn (array $row) => $row['past'])->values()->all();
        $this->pastEvents = $rows->filter(fn (array $row) => $row['past'])->reverse()->values()->all();
    }

    public function navTitle(): string
    {
        return 'Events';
    }

    public function render(): View
    {
        return view('native.events-index');
    }
}
