<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\NativeComponents\AttendeeDetail;
use App\NativeComponents\AttendeesIndex;
use Native\Mobile\Testing\Native;

covers(AttendeesIndex::class);

beforeEach(function () {
    Native::fakeBridge();
    $this->site = Site::factory()->create();
    $this->event = Event::factory()->create(['site_id' => $this->site->id]);

    $this->ada = Attendee::factory()->create([
        'site_id' => $this->site->id,
        'wp_event_id' => $this->event->wp_event_id,
        'holder_name' => 'Ada Lovelace',
        'holder_email' => 'ada@example.test',
    ]);
    $this->grace = Attendee::factory()->checkedIn()->create([
        'site_id' => $this->site->id,
        'wp_event_id' => $this->event->wp_event_id,
        'holder_name' => 'Grace Hopper',
        'holder_email' => 'grace@example.test',
    ]);
});

function attendeesScreen(Event $event)
{
    return Native::test(AttendeesIndex::class, params: ['event' => $event->id]);
}

it('lists all attendees with check-in badges and counts', function () {
    attendeesScreen($this->event)
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper')
        ->assertSee('1 / 2 checked in');
});

it('searches by name and email, escaping like wildcards', function () {
    attendeesScreen($this->event)
        ->set('query', 'lovelace')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper')
        ->set('query', 'grace@')
        ->assertSee('Grace Hopper')
        ->assertDontSee('Ada Lovelace')
        ->set('query', '%')
        ->assertDontSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper')
        ->assertSee('No attendees match.');
});

it('filters by check-in state', function () {
    attendeesScreen($this->event)
        ->call('setFilter', 'in')
        ->assertSee('Grace Hopper')
        ->assertDontSee('Ada Lovelace')
        ->call('setFilter', 'out')
        ->assertSee('Ada Lovelace')
        ->assertDontSee('Grace Hopper')
        ->call('setFilter', 'all')
        ->assertSee('Ada Lovelace')
        ->assertSee('Grace Hopper');
});

it('excludes attendees of other events and sites', function () {
    Attendee::factory()->create([
        'site_id' => $this->site->id,
        'wp_event_id' => 999,
        'holder_name' => 'Wrong Event',
    ]);

    attendeesScreen($this->event)
        ->assertDontSee('Wrong Event');
});

it('opens the attendee detail screen', function () {
    attendeesScreen($this->event)
        ->call('open', $this->ada->id)
        ->assertNavigatedTo("/attendees/{$this->ada->id}")
        ->followNavigation()
        ->assertScreen(AttendeeDetail::class);
});

it('checks in an eligible attendee from a swipe action', function () {
    $eligible = Attendee::factory()->create([
        'site_id' => $this->site->id,
        'wp_event_id' => $this->event->wp_event_id,
        'holder_name' => 'Swipe Me',
    ]);

    Native::test(AttendeesIndex::class, params: ['event' => $this->event->id])
        ->call('swipeCheckin', $eligible->id)
        ->assertSee('Swipe Me, checked in');

    expect($eligible->fresh()->checked_in)->toBeTrue();
});

it('refuses a swipe check-in for a refunded attendee', function () {
    $refunded = Attendee::factory()->create([
        'site_id' => $this->site->id,
        'wp_event_id' => $this->event->wp_event_id,
        'order_status' => 'refunded',
    ]);

    Native::test(AttendeesIndex::class, params: ['event' => $this->event->id])
        ->call('swipeCheckin', $refunded->id);

    expect($refunded->fresh()->checked_in)->toBeFalse();
});

it('marks refunded attendees with a refund glyph and no swipe action', function () {
    Attendee::factory()->create([
        'site_id' => $this->site->id,
        'wp_event_id' => $this->event->wp_event_id,
        'holder_name' => 'Refund Ray',
        'order_status' => 'refunded',
    ]);

    Native::test(AttendeesIndex::class, params: ['event' => $this->event->id])
        ->assertSee('Refund Ray, refunded')
        ->assertDontSee('Refund Ray, checked in');
});

it('gives each ineligible order status its own glyph label', function () {
    foreach (['pending' => 'payment pending', 'cancelled' => 'cancelled', 'denied' => 'not going'] as $status => $label) {
        Attendee::factory()->create([
            'site_id' => $this->site->id,
            'wp_event_id' => $this->event->wp_event_id,
            'holder_name' => ucfirst($status).' Person',
            'order_status' => $status,
        ]);
    }

    Native::test(AttendeesIndex::class, params: ['event' => $this->event->id])
        ->assertSee('Pending Person, payment pending')
        ->assertSee('Cancelled Person, cancelled')
        ->assertSee('Denied Person, not going');
});
