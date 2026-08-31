<?php

use App\Models\Attendee;
use App\Models\CheckinOperation;
use App\Models\Event;
use App\Models\Site;
use App\NativeComponents\EventHome;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use Illuminate\Support\Str;
use Native\Mobile\Testing\Native;

covers(EventHome::class);

beforeEach(function () {
    Native::fakeBridge();
    $this->site = Site::factory()->create();
    $this->event = Event::factory()->create(['site_id' => $this->site->id]);
});

it('redirects to the events list for an unknown event id', function () {
    Native::test(EventHome::class, params: ['event' => 999999])
        ->assertReplacedWith('/events');
});

it('runs the initial attendee sync on first visit and shows door counts', function () {
    Native::test(EventHome::class, params: ['event' => $this->event->id])
        ->assertSee('Fixture Fest 2026')
        ->assertSee('Start Scanning')
        ->assertSet('total', 50)
        ->assertSet('checkedIn', 14)
        ->assertSet('syncing', false);

    expect(Attendee::where('site_id', $this->site->id)->count())->toBe(50)
        ->and($this->event->refresh()->last_synced_at)->not->toBeNull();
});

it('does not re-sync on later visits but still shows local counts', function () {
    $this->event->update(['last_synced_at' => now(), 'sync_cursor' => now()->toIso8601ZuluString()]);
    Attendee::factory()->count(3)->create([
        'site_id' => $this->site->id,
        'wp_event_id' => $this->event->wp_event_id,
        'checked_in' => true,
    ]);

    Native::test(EventHome::class, params: ['event' => $this->event->id])
        ->assertSet('total', 3)
        ->assertSet('checkedIn', 3);
});

it('surfaces sync failures without losing the screen', function () {
    app(ApiClient::class)->failNextWith(new ApiException('server exploded', 500));

    Native::test(EventHome::class, params: ['event' => $this->event->id])
        ->assertSee('Sync failed')
        ->assertSet('syncing', false)
        ->assertSet('total', 0);
});

it('shows pending check-in operations awaiting sync', function () {
    $this->event->update(['last_synced_at' => now()]);
    CheckinOperation::create([
        'op_id' => (string) Str::uuid(),
        'site_id' => $this->site->id,
        'wp_attendee_id' => 9001,
        'wp_event_id' => $this->event->wp_event_id,
        'action' => 'checkin',
        'occurred_at' => now()->toIso8601ZuluString(),
    ]);

    Native::test(EventHome::class, params: ['event' => $this->event->id])
        ->assertSet('pendingOps', 1)
        ->assertSee('1 check-ins waiting to sync');
});

it('navigates to the scanner', function () {
    $this->event->update(['last_synced_at' => now()]);

    Native::test(EventHome::class, params: ['event' => $this->event->id])
        ->tap('scan-btn')
        ->assertNavigatedTo("/scan/{$this->event->id}");
});

it('manual sync-now button pulls fresh state', function () {
    $this->event->update(['last_synced_at' => now()->subHour()]);

    Native::test(EventHome::class, params: ['event' => $this->event->id])
        ->assertSet('total', 0)
        ->tap('sync-btn')
        ->assertSet('total', 50);
});

it('opens the event page in the browser for a walk-up registration', function () {
    $bridge = Native::fakeBridge();
    $bridge->respondTo('Browser.Open', ['status' => 'ok']);

    $this->site->update(['base_url' => 'https://example.test']);

    Native::test(EventHome::class, ['event' => $this->event->id])
        ->assertSee('Register walk-up')
        ->call('registerWalkUp');

    $bridge->assertCalled(
        'Browser.Open',
        fn (array $params) => ($params['url'] ?? '') === 'https://example.test/?p='.$this->event->wp_event_id,
    );
});

it('hides walk-up registration once the event has ended', function () {
    $this->event->update([
        'starts_at' => now()->subDays(2)->format('Y-m-d H:i:s'),
        'ends_at' => now()->subDays(2)->addHours(3)->format('Y-m-d H:i:s'),
    ]);

    Native::test(EventHome::class, ['event' => $this->event->id])
        ->assertDontSee('Register walk-up');
});
