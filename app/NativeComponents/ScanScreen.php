<?php

namespace App\NativeComponents;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\Services\CheckinService;
use App\Services\Qr\QrParser;
use App\Services\Scan\ScanOutcome;
use App\Services\Scan\ScanValidator;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Attributes\Poll;
use Native\Mobile\Edge\Layouts\Builders\TabBarOptions;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Events\Scanner\ScannerCancelled;
use Native\Mobile\Facades\Haptics;
use Native\Mobile\Facades\Scanner;
use Throwable;

/**
 * The door-scanning loop (PLAN.md Stage 4): continuous QR scanning with
 * full-bleed GREEN / AMBER / RED results. All validation is local SQLite —
 * scanning works fully offline; check-ins queue for sync.
 *
 * GREEN auto-dismisses after ~2s; AMBER and RED require a tap so staff
 * consciously acknowledge duplicates and rejections.
 */
class ScanScreen extends NativeComponent
{
    private const DEBOUNCE_SECONDS = 3;

    private const GREEN_DISMISS_SECONDS = 2;

    /** scanning | green | amber | red | unavailable */
    public string $phase = 'scanning';

    public string $reasonLabel = '';

    public string $attendeeName = '';

    public string $ticketName = '';

    public string $checkedInInfo = '';

    public int $sessionScans = 0;

    /** Which event the last ticket belonged to — only shown in any-event mode. */
    public string $eventLabel = '';

    /** Header line: the pinned event, or "All events" from the Scan tab. */
    public string $contextLabel = '';

    /** No event pinned: the event comes from whatever ticket is scanned. */
    public bool $anyEvent = false;

    /** Local id of the scanned attendee — View details + group actions. */
    public int $attendeeId = 0;

    /**
     * Linked tickets: others on the same order. Rows:
     * {id, name, ticket, checked_in, eligible}.
     *
     * @var array<int, array{id: int, name: string, ticket: string, checked_in: bool, eligible: bool}>
     */
    public array $groupMates = [];

    protected ?Event $event = null;

    protected ?Site $site = null;

    /** @var array<string, float> raw code → last-seen unix time (duplicate-read debounce) */
    protected array $recentCodes = [];

    /** Public so tests can rewind the clock without a real sleep. */
    public float $resultShownAt = 0.0;

    public function mount(): void
    {
        $eventId = (int) $this->param('event', 0);

        if ($eventId > 0) {
            $this->event = Event::find($eventId);

            if (! $this->event) {
                $this->replace('/events');

                return;
            }

            $this->site = $this->event->site;
            $this->contextLabel = $this->event->title;
        } else {
            // Scan tab: no event in context. Tickets are matched against
            // every event downloaded for the active site.
            $this->anyEvent = true;
            $this->site = Site::current();

            if (! $this->site) {
                $this->replace('/connect');

                return;
            }

            $this->contextLabel = 'All events · '.$this->site->name;
        }

        $this->startScanner();
    }

    /** The pushed, event-pinned scanner is a detail screen; the tab isn't. */
    public function tabBarOptions(): ?TabBarOptions
    {
        return TabBarOptions::make()->hidden(! $this->anyEvent);
    }

    public function startScanner(): void
    {
        try {
            Scanner::scan()
                ->prompt('Point at a ticket QR code')
                ->formats(['qr'])
                ->continuous()
                ->id('door-scan')
                ->scan();
        } catch (Throwable) {
            // Native scanner unavailable (plugin not compiled into this build).
            $this->phase = 'unavailable';
        }
    }

    #[On(CodeScanned::class)]
    public function onCodeScanned(string $data, string $format, ?string $id = null): void
    {
        // Continuous scanners re-fire the same frame; and while a result is
        // on screen, new reads are ignored (AMBER/RED demand acknowledgment).
        if ($this->phase !== 'scanning' || $this->isDuplicateRead($data)) {
            return;
        }

        $parsed = app(QrParser::class)->parse($data);

        $result = $this->event
            ? app(ScanValidator::class)->validate($parsed, $this->event)
            : app(ScanValidator::class)->validateForSite($parsed, $this->site);

        $this->sessionScans++;
        $this->resultShownAt = microtime(true);
        $this->attendeeName = $result->attendee->holder_name ?? '';
        $this->ticketName = $result->attendee->ticket_name ?? '';
        $this->attendeeId = $result->attendee->id ?? 0;
        $this->loadGroup($result->attendee, $result->outcome);
        $this->reasonLabel = $result->reason->label();
        // In any-event mode staff can't see which door they're at from the
        // screen alone, so name the event the ticket resolved to.
        $this->eventLabel = $this->anyEvent ? ($result->event?->title ?? '') : '';

        match ($result->outcome) {
            ScanOutcome::Green => $this->showGreen($result->attendee, $result->event),
            ScanOutcome::Amber => $this->showAmber($result->attendee),
            ScanOutcome::Red => $this->showRed(),
        };
    }

    #[On(ScannerCancelled::class)]
    public function onScannerCancelled(): void
    {
        // The Scan tab is a root screen — there is nothing to pop back to,
        // so closing the camera lands on the events list instead.
        $this->anyEvent ? $this->replace('/events') : $this->back();
    }

    /**
     * GREEN auto-returns to scanning — unless groupmates still need
     * checking in, in which case the card stays up for group check-in.
     * Ticks are cheap no-ops otherwise.
     */
    #[Poll(500)]
    public function tick(): void
    {
        if ($this->phase === 'green'
            && ! $this->hasEligibleMates()
            && (microtime(true) - $this->resultShownAt) >= self::GREEN_DISMISS_SECONDS) {
            $this->dismiss();
        }
    }

    /** Check in one linked ticket from the result card. */
    public function checkinMate(int $attendeeId): void
    {
        $mate = Attendee::find($attendeeId);
        $event = $mate ? Event::query()
            ->where('site_id', $mate->site_id)
            ->where('wp_event_id', $mate->wp_event_id)
            ->first() : null;

        if (! $mate || ! $event || ! $mate->isEligibleForCheckin() || $mate->checked_in) {
            return;
        }

        app(CheckinService::class)->checkin($mate, $event);

        foreach ($this->groupMates as &$row) {
            if ($row['id'] === $mate->id) {
                $row['checked_in'] = true;
                $row['eligible'] = false;
            }
        }
        unset($row);
    }

    /** Check in every eligible linked ticket at once. */
    public function checkinGroup(): void
    {
        foreach ($this->groupMates as $row) {
            if ($row['eligible']) {
                $this->checkinMate($row['id']);
            }
        }
    }

    /** Open the scanned attendee's detail screen. */
    public function viewDetails(): void
    {
        if ($this->attendeeId > 0) {
            $this->navigate("/attendees/{$this->attendeeId}");
        }
    }

    private function hasEligibleMates(): bool
    {
        return (bool) array_filter($this->groupMates, fn (array $row) => $row['eligible']);
    }

    /**
     * Linked tickets for the result card. Only GREEN/AMBER load a group —
     * a RED scan should keep staff focused on the rejection.
     */
    private function loadGroup(?Attendee $attendee, ScanOutcome $outcome): void
    {
        $this->groupMates = [];

        if (! $attendee || $outcome === ScanOutcome::Red) {
            return;
        }

        $this->groupMates = $attendee->groupMates()
            ->map(fn (Attendee $mate) => [
                'id' => $mate->id,
                'name' => $mate->holder_name,
                'ticket' => $mate->ticket_name ?? '',
                'checked_in' => (bool) $mate->checked_in,
                'eligible' => $mate->isEligibleForCheckin() && ! $mate->checked_in,
            ])
            ->all();
    }

    public function dismiss(): void
    {
        if ($this->phase === 'amber' || $this->phase === 'red' || $this->phase === 'green') {
            $this->phase = 'scanning';
        }
    }

    private function showGreen($attendee, ?Event $event): void
    {
        app(CheckinService::class)->checkin($attendee, $event ?? $this->event);
        $this->vibrate(1);
        $this->phase = 'green';
    }

    private function showAmber($attendee): void
    {
        $this->checkedInInfo = trim(sprintf(
            'Already checked in %s%s',
            $attendee->checked_in_at ? "at {$attendee->checked_in_at}" : '',
            $attendee->checked_in_by ? " via {$attendee->checked_in_by}" : '',
        ));
        $this->vibrate(2);
        $this->phase = 'amber';
    }

    private function showRed(): void
    {
        $this->vibrate(2);
        $this->phase = 'red';
    }

    private function isDuplicateRead(string $data): bool
    {
        $now = microtime(true);
        $this->recentCodes = array_filter(
            $this->recentCodes,
            fn (float $seen) => ($now - $seen) < self::DEBOUNCE_SECONDS,
        );

        if (isset($this->recentCodes[$data])) {
            return true;
        }

        $this->recentCodes[$data] = $now;

        return false;
    }

    private function vibrate(int $times): void
    {
        try {
            for ($i = 0; $i < $times; $i++) {
                Haptics::vibrate();
            }
        } catch (Throwable) {
            // Haptics are best-effort.
        }
    }

    public function navTitle(): string
    {
        return 'Scan';
    }

    public function render(): View
    {
        return view('native.scan-screen');
    }
}
