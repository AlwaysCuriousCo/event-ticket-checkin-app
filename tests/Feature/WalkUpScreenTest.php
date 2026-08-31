<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\NativeComponents\BrowseScreen;
use App\NativeComponents\WalkUpScreen;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Testing\Native;

covers(WalkUpScreen::class);

beforeEach(function () {
    Native::fakeBridge();
    $this->site = Site::factory()->create();
    $this->event = Event::factory()->create(['site_id' => $this->site->id]);
});

it('lists the event tickets with the first preselected', function () {
    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->assertSee('General Admission')
        ->assertSee('VIP')
        ->assertSee('RSVP')
        ->assertSet('ticketId', 701);
});

it('registers a cash walk-up and mirrors them locally checked in', function () {
    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->set('name', 'Pat Walkup')
        ->set('email', 'pat@example.test')
        ->call('register')
        ->assertSet('phase', 'done')
        ->assertSee('Pat Walkup')
        ->assertSee('registered and checked in');

    $attendee = Attendee::query()
        ->where('site_id', $this->site->id)
        ->where('holder_name', 'Pat Walkup')
        ->first();

    expect($attendee)->not->toBeNull()
        ->and($attendee->checked_in)->toBeTrue()
        ->and($attendee->order_status)->toBe('completed')
        ->and($attendee->wp_ticket_id)->toBe(701);
});

it('requires a name before registering', function () {
    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->call('register')
        ->assertSet('phase', 'form')
        ->assertSee('Attendee name is required.');

    expect(Attendee::count())->toBe(0);
});

it('surfaces a registration failure without leaving the form', function () {
    $native = Native::test(WalkUpScreen::class, params: ['event' => $this->event->id]);

    app(ApiClient::class)->failNextWith(new ApiException('Walk-up registration is disabled for this event.', 403));

    $native->set('name', 'Pat Walkup')
        ->call('register')
        ->assertSet('phase', 'form')
        ->assertSee('Registration failed');
});

it('shows the offline state when tickets cannot load, and retries', function () {
    app(ApiClient::class)->failNextWith(new ApiException('No connection'));

    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->assertSet('phase', 'offline')
        ->assertSee('needs a connection')
        ->tap('retry-btn')
        ->assertSet('phase', 'form')
        ->assertSet('ticketId', 701);
});

it('resets the form for the next walk-up', function () {
    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->set('name', 'Pat Walkup')
        ->call('register')
        ->assertSet('phase', 'done')
        ->tap('another-btn')
        ->assertSet('phase', 'form')
        ->assertSet('name', '')
        ->assertSet('payment', 'cash');
});

it('hands card buyers to the site checkout in the in-app browser', function () {
    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->call('payByCard')
        ->assertNavigatedTo('/browse')
        ->followNavigation()
        ->assertScreen(BrowseScreen::class)
        ->assertSet('url', rtrim($this->site->base_url, '/').'/?p='.$this->event->wp_event_id)
        ->assertSet('title', 'Buy ticket');
});

it('recovers the browse target from cache when the navigate payload is dropped', function () {
    // On-device the navigate() data payload can arrive empty; the sender
    // stashes a cache copy that BrowseScreen falls back to.
    Cache::put('ticketscanner.browse', ['url' => 'https://example.test/?p=501', 'title' => 'Buy ticket'], 300);

    Native::test(BrowseScreen::class)
        ->assertSet('url', 'https://example.test/?p=501')
        ->assertSet('title', 'Buy ticket');
});

it('bounces back when walk-up is disabled for the event', function () {
    $this->event->update(['allow_walkup' => false]);

    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->assertWentBack();
});

it('bounces back once the event has ended', function () {
    $this->event->update([
        'starts_at' => now()->subDays(2)->format('Y-m-d H:i:s'),
        'ends_at' => now()->subDays(2)->addHours(3)->format('Y-m-d H:i:s'),
    ]);

    Native::test(WalkUpScreen::class, params: ['event' => $this->event->id])
        ->assertWentBack();
});
