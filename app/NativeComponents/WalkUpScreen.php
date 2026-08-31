<?php

namespace App\NativeComponents;

use App\Models\Event;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use App\Services\DeviceIdentity;
use App\Services\SyncEngine;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Native\Mobile\Edge\NativeComponent;

/**
 * Box-office walk-up registration. Cash and comp sales post straight to the
 * plugin's /register endpoint (real attendee, checked in on the spot); card
 * buyers are handed to the site's own checkout in the in-app browser so the
 * configured gateway — not this app — touches the payment.
 *
 * Online-only by design: registration creates the attendee server-side so
 * the ticket exists everywhere, not just on this device.
 */
class WalkUpScreen extends NativeComponent
{
    public string $eventTitle = '';

    /** @var array<int, array{id: int, name: string, provider: string, price: float|int}> */
    public array $tickets = [];

    public int $ticketId = 0;

    public string $name = '';

    public string $email = '';

    public string $payment = 'cash';

    /** form | done | offline */
    public string $phase = 'form';

    public string $error = '';

    public bool $busy = false;

    /** @var array{name: string, ticket: string} */
    public array $registered = ['name' => '', 'ticket' => ''];

    protected ?Event $event = null;

    public function mount(): void
    {
        $this->event = Event::find((int) $this->param('event', 0));

        if (! $this->event || ! $this->event->allow_walkup || $this->event->hasEnded()) {
            $this->back();

            return;
        }

        $this->eventTitle = $this->event->title;
        $this->loadTickets();
    }

    public function loadTickets(): void
    {
        $this->error = '';
        $this->phase = 'form';

        try {
            $this->tickets = app(ApiClient::class)->tickets($this->event->site, $this->event->wp_event_id)['tickets'];
        } catch (ApiException) {
            $this->phase = 'offline';

            return;
        }

        if ($this->tickets === []) {
            $this->error = 'This event has no tickets to sell.';

            return;
        }

        $this->ticketId = $this->tickets[0]['id'];
    }

    public function selectTicket(int $ticketId): void
    {
        $this->ticketId = $ticketId;
    }

    public function setPayment(string $payment): void
    {
        if (in_array($payment, ['cash', 'comp'], true)) {
            $this->payment = $payment;
        }
    }

    public function register(): void
    {
        $this->error = '';
        $name = trim($this->name);

        if ($name === '') {
            $this->error = 'Attendee name is required.';

            return;
        }

        $this->busy = true;

        try {
            $result = app(ApiClient::class)->registerWalkUp($this->event->site, $this->event->wp_event_id, [
                'ticket_id' => $this->ticketId,
                'name' => $name,
                'email' => trim($this->email),
                'payment' => $this->payment,
                'check_in' => true,
                'device_id' => app(DeviceIdentity::class)->id(),
            ]);
        } catch (ApiException $e) {
            $this->busy = false;
            $this->error = "Registration failed: {$e->getMessage()}";

            return;
        }

        // Mirror the new attendee locally so they scan GREEN immediately.
        app(SyncEngine::class)->applyServerAttendee($this->event->site, $result['attendee']);

        $ticket = collect($this->tickets)->firstWhere('id', $this->ticketId);
        $this->registered = ['name' => $name, 'ticket' => $ticket['name'] ?? ''];
        $this->busy = false;
        $this->phase = 'done';
    }

    public function registerAnother(): void
    {
        $this->name = '';
        $this->email = '';
        $this->payment = 'cash';
        $this->phase = 'form';
    }

    /** Card buyers use the site's own checkout — the gateway handles payment. */
    public function payByCard(): void
    {
        $url = rtrim($this->event->site->base_url, '/').'/?p='.$this->event->wp_event_id;

        // navigate() data can be dropped on-device; the cache copy survives.
        Cache::put('ticketscanner.browse', ['url' => $url, 'title' => 'Buy ticket'], 300);
        $this->navigate('/browse', ['url' => $url, 'title' => 'Buy ticket']);
    }

    public function navTitle(): string
    {
        return 'Register walk-up';
    }

    public function render(): View
    {
        return view('native.walk-up-screen');
    }
}
