<?php

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\NativeComponents\EventHome;
use App\NativeComponents\EventsIndex;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use App\Services\Api\FixtureApiClient;
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

    // Stay offline for the mount pull so these local-only events survive
    // the authoritative-pull prune. Search itself never hits the server.
    app(ApiClient::class)->failNextWith(new ApiException('offline'));
    Native::test(EventsIndex::class)
        ->set('query', 'charity') // set() fires the updatedQuery hook itself
        ->assertSee('Charity Auction')
        ->assertDontSee('Monthly Meetup');
});

it('searches locally without re-pulling from the server', function () {
    Site::factory()->create();

    $screen = Native::test(EventsIndex::class)->assertSee('Fixture Fest 2026');

    // If typing triggered a pull, this armed failure would flip the screen
    // to the offline banner — a local filter never consumes it.
    app(ApiClient::class)->failNextWith(new ApiException('offline'));

    $screen->set('query', 'fixture')
        ->assertSet('error', '')
        ->assertSee('Fixture Fest 2026');
});

it('walks every page before pruning', function () {
    $site = Site::factory()->create();
    Event::factory()->create(['site_id' => $site->id, 'wp_event_id' => 601, 'title' => 'Second Page Event']);

    // Two-page server response: 601 only appears on page 2. A prune that
    // trusts page 1 alone would delete it.
    app()->instance(ApiClient::class, new class(base_path('docs/api/fixtures')) extends FixtureApiClient
    {
        public function events(Site $site, int $page = 1): array
        {
            $first = parent::events($site, $page)['events'];

            return $page === 1
                ? ['events' => $first, 'total' => count($first) + 1, 'page' => 1, 'per_page' => count($first), 'has_more' => true]
                : ['events' => [[
                    'id' => 601, 'title' => 'Second Page Event',
                    'start_date' => now()->addDay()->format('Y-m-d H:i:s'),
                    'end_date' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
                    'timezone' => 'UTC', 'venue' => null,
                    'attendee_count' => 0, 'checked_in_count' => 0,
                ]], 'total' => 2, 'page' => 2, 'per_page' => 1, 'has_more' => false];
        }
    });

    Native::test(EventsIndex::class)->assertSee('Second Page Event');

    expect(Event::where('wp_event_id', 601)->exists())->toBeTrue();
});

it('re-reads the active site on resume after a switch', function () {
    Site::factory()->create(['name' => 'First Site', 'is_active' => true]);
    $second = Site::factory()->create(['name' => 'Second Site', 'base_url' => 'https://second.test', 'is_active' => false]);

    $screen = Native::test(EventsIndex::class)->assertSet('siteName', 'First Site');

    $second->activate();

    $screen->call('onResume')->assertSet('siteName', 'Second Site');
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

it('keeps server counts while searching', function () {
    Site::factory()->create();

    // Mount pulls 14/50 from the server; this screen never syncs attendee
    // rows, so a search rebuild must reuse those counts, not local zeros.
    Native::test(EventsIndex::class)
        ->assertSee('14 / 50 checked in')
        ->set('query', 'fixture')
        ->assertSee('14 / 50 checked in')
        ->set('query', '')
        ->assertSee('14 / 50 checked in');
});

it('drops the previous site counts when the active site changes', function () {
    Site::factory()->create(['name' => 'First Site', 'is_active' => true]);
    $second = Site::factory()->create(['name' => 'Second Site', 'base_url' => 'https://second.test', 'is_active' => false]);

    // Same wp_event_id as the fixture event on the first site — WordPress
    // post IDs collide across sites, so counts must never carry over.
    Event::factory()->create(['site_id' => $second->id, 'wp_event_id' => 501, 'title' => 'Second Site Event']);

    $screen = Native::test(EventsIndex::class)->assertSee('14 / 50 checked in');

    $second->activate();
    app(ApiClient::class)->failNextWith(new ApiException('offline'));

    $screen->call('onResume')
        ->assertSee('Second Site Event')
        ->assertDontSee('14 / 50 checked in');
});
