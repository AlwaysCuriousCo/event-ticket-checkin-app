<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\NativeComponents\EventHome;
use App\NativeComponents\EventsIndex;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use Native\Mobile\Testing\Native;

covers(EventsIndex::class);

beforeEach(function () {
    Native::fakeBridge();
});

it('redirects to connect when no site exists', function () {
    Native::test(EventsIndex::class)->assertReplacedWith('/connect');
});

it('lists events from the API with server counts and stores them locally', function () {
    $site = Site::factory()->create();

    Native::test(EventsIndex::class)
        ->assertSee('Fixture Fest 2026')
        ->assertSee('14 / 50 checked in');

    $event = Event::sole();
    expect($event->site_id)->toBe($site->id)
        ->and($event->wp_event_id)->toBe(501)
        ->and($event->venue)->toBe('The Grand Hall');
});

it('falls back to cached events with local counts when the API is unreachable', function () {
    $site = Site::factory()->create();
    Event::factory()->create(['site_id' => $site->id, 'title' => 'Cached Event']);

    app(ApiClient::class)->failNextWith(new ApiException('timeout'));

    Native::test(EventsIndex::class)
        ->assertSee('Offline — showing cached events.')
        ->assertSee('Cached Event')
        ->assertSee('0 / 0 checked in');
});

it('opens an event', function () {
    Site::factory()->create();

    $harness = Native::test(EventsIndex::class);
    $eventId = Event::sole()->id;

    $harness->call('open', $eventId)
        ->assertNavigatedTo("/events/{$eventId}")
        ->followNavigation()
        ->assertScreen(EventHome::class);
});

it('treats an unparseable site timezone as UTC instead of crashing', function () {
    $site = Site::factory()->create();
    $event = Event::factory()->create([
        'site_id' => $site->id,
        'timezone' => 'UTC+0', // what WordPress reports for an offset-configured site
        'starts_at' => now()->subDay()->format('Y-m-d H:i:s'),
        'ends_at' => null,
    ]);

    expect($event->hasEnded())->toBeTrue();
});

it('reads WordPress offset timezones as real offsets', function () {
    $site = Site::factory()->create();
    $event = Event::factory()->make([
        'site_id' => $site->id,
        'timezone' => 'UTC-5',
        'starts_at' => now()->addHours(2)->format('Y-m-d H:i:s'), // wall clock at UTC-5 → 7h out
        'ends_at' => null,
    ]);

    expect($event->hasEnded())->toBeFalse();
});

it('filters the list by event title or venue', function () {
    $site = Site::factory()->create();
    Event::factory()->create([
        'site_id' => $site->id,
        'wp_event_id' => 503,
        'title' => 'Charity Auction',
        'venue' => 'The Grand Hall',
        'starts_at' => now()->addDays(3)->format('Y-m-d H:i:s'),
    ]);
    Event::factory()->create([
        'site_id' => $site->id,
        'wp_event_id' => 504,
        'title' => 'Monthly Meetup',
        'venue' => 'Back Room',
        'starts_at' => now()->addDays(4)->format('Y-m-d H:i:s'),
    ]);

    // Stay offline for both loads so these local-only events survive the
    // authoritative-pull prune.
    app(ApiClient::class)->failNextWith(new ApiException('offline'));
    $screen = Native::test(EventsIndex::class);

    app(ApiClient::class)->failNextWith(new ApiException('offline'));
    $screen->set('query', 'charity') // set() fires the updatedQuery hook itself
        ->assertSee('Charity Auction')
        ->assertDontSee('Monthly Meetup');
});

it('prunes local events the server no longer returns', function () {
    $site = Site::factory()->create();
    $stale = Event::factory()->create(['site_id' => $site->id, 'wp_event_id' => 999, 'title' => 'Deleted On Server']);
    Attendee::factory()->create(['site_id' => $site->id, 'wp_event_id' => 999]);

    Native::test(EventsIndex::class)->assertDontSee('Deleted On Server');

    expect(Event::whereKey($stale->id)->exists())->toBeFalse()
        ->and(Attendee::where('wp_event_id', 999)->count())->toBe(0);
});

it('keeps cached events when offline', function () {
    $site = Site::factory()->create();
    Event::factory()->create(['site_id' => $site->id, 'wp_event_id' => 999, 'title' => 'Cached Event']);

    app(ApiClient::class)->failNextWith(new ApiException('timeout'));

    Native::test(EventsIndex::class)->assertSee('Cached Event');
});
