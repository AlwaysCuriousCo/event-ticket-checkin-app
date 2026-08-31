<?php

use App\Models\Attendee;
use App\Models\CheckinOperation;
use App\Models\Event;
use App\Models\Site;
use App\NativeComponents\ScanScreen;
use App\Services\NativeScanner;
use Native\Mobile\Events\Scanner\CodeScanned;
use Native\Mobile\Events\Scanner\ScannerCancelled;
use Native\Mobile\Testing\Native;

covers(ScanScreen::class);

beforeEach(function () {
    $this->bridge = Native::fakeBridge();
    $this->site = Site::factory()->create();
    $this->event = Event::factory()->create(['site_id' => $this->site->id, 'last_synced_at' => now()]);
});

function ticketQrUrl(Attendee $attendee, ?string $code = null): string
{
    return 'https://example.test/?event_qr_code=1'
        .'&ticket_id='.$attendee->wp_attendee_id
        .'&event_id='.$attendee->wp_event_id
        .'&security_code='.($code ?? $attendee->security_code)
        .'&path=%2Fwp-json%2Ftribe%2Ftickets%2Fv1%2Fqr';
}

function scanScreen(Event $event)
{
    return Native::test(ScanScreen::class, params: ['event' => $event->id]);
}

function doorAttendee(Site $site, Event $event, array $attributes = []): Attendee
{
    return Attendee::factory()->create([
        'site_id' => $site->id,
        'wp_event_id' => $event->wp_event_id,
        ...$attributes,
    ]);
}

it('redirects to the events list for an unknown event', function () {
    Native::test(ScanScreen::class, params: ['event' => 424242])
        ->assertReplacedWith('/events');
});

it('starts a continuous qr scan on mount', function () {
    scanScreen($this->event)
        ->assertSet('phase', 'scanning')
        ->assertSee('Ready to scan')
        ->assertNativeCalled('Scanner.Scan', fn ($p) => ($p['formats'] ?? null) === ['qr'] && ($p['continuous'] ?? false) === true);
});

it('shows GREEN and checks in a valid attendee, queueing exactly one operation', function () {
    $attendee = doorAttendee($this->site, $this->event, ['holder_name' => 'Ada Lovelace']);

    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'green')
        ->assertSee('Ada Lovelace')
        ->assertSee('Valid Ticket');

    // NB: the sync queue driver in tests runs the debounced SyncEventJob
    // immediately, so the operation may already be marked synced — assert
    // existence (exactly one op), not pending state.
    expect($attendee->refresh()->checked_in)->toBeTrue()
        ->and(CheckinOperation::count())->toBe(1);

    $this->bridge->assertCalled('Device.Vibrate');
});

it('debounces duplicate reads of the same code', function () {
    $attendee = doorAttendee($this->site, $this->event);
    $qr = ticketQrUrl($attendee);

    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => $qr, 'format' => 'qr'])
        ->call('dismiss') // back to scanning within the debounce window
        ->emitNative(CodeScanned::class, ['data' => $qr, 'format' => 'qr'])
        ->assertSet('phase', 'scanning');    // second read ignored entirely

    expect(CheckinOperation::count())->toBe(1);
});

it('shows AMBER with who and when for an already-checked-in attendee', function () {
    $attendee = doorAttendee($this->site, $this->event, ['holder_name' => 'Grace Hopper'])
        ->refresh();
    $attendee->update(['checked_in' => true, 'checked_in_at' => '2026-08-11T15:11:00Z', 'checked_in_by' => 'front-door-ipad']);

    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'amber')
        ->assertSee('Grace Hopper')
        ->assertSee('front-door-ipad');

    // No new operation for a duplicate.
    expect(CheckinOperation::count())->toBe(0);
});

it('re-scanning the same ticket after GREEN yields AMBER (new read, not debounced)', function () {
    $attendee = doorAttendee($this->site, $this->event);
    $screen = scanScreen($this->event);

    $screen->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'green');

    // Simulate the debounce window passing by using a fresh screen (new visit).
    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'amber');
});

it('shows RED for a wrong security code', function () {
    $attendee = doorAttendee($this->site, $this->event);

    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee, 'deadbeef'), 'format' => 'qr'])
        ->assertSet('phase', 'red')
        ->assertSee('NOT VALID')
        ->assertSee('Security code does not match');

    expect($attendee->refresh()->checked_in)->toBeFalse()
        ->and(CheckinOperation::count())->toBe(0);
});

it('shows RED for a refunded order', function () {
    $attendee = doorAttendee($this->site, $this->event, ['order_status' => 'refunded']);

    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'red')
        ->assertSee('Order not complete');
});

it('shows RED for a foreign qr payload', function () {
    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => 'WIFI:S:venue;T:WPA;P:x;;', 'format' => 'qr'])
        ->assertSet('phase', 'red')
        ->assertSee('Not a ticket QR');
});

it('ignores reads while a result is on screen', function () {
    $first = doorAttendee($this->site, $this->event);
    $second = doorAttendee($this->site, $this->event);

    scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($first), 'format' => 'qr'])
        ->assertSet('phase', 'green')
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($second), 'format' => 'qr'])
        ->assertSet('phase', 'green');

    expect($second->refresh()->checked_in)->toBeFalse()
        ->and(CheckinOperation::count())->toBe(1);
});

it('GREEN auto-dismisses back to scanning after the linger window', function () {
    $attendee = doorAttendee($this->site, $this->event);
    $screen = scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'green');

    $screen->firePoll('tick')->assertSet('phase', 'green'); // too soon

    // Rewind the shown-at timestamp instead of sleeping.
    $instance = $screen->instance();
    (fn () => $this->resultShownAt = microtime(true) - 3)->call($instance);

    $screen->firePoll('tick')->assertSet('phase', 'scanning');
});

it('AMBER requires a tap to dismiss and then resumes scanning', function () {
    $attendee = doorAttendee($this->site, $this->event, ['checked_in' => true]);
    $screen = scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($attendee), 'format' => 'qr'])
        ->assertSet('phase', 'amber');

    $instance = $screen->instance();
    (fn () => $this->resultShownAt = microtime(true) - 60)->call($instance);
    $screen->firePoll('tick')->assertSet('phase', 'amber'); // poll never clears amber

    $screen->tap('result-amber')->assertSet('phase', 'scanning');
});

it('leaves the screen when the native scanner is cancelled', function () {
    scanScreen($this->event)
        ->emitNative(ScannerCancelled::class)
        ->assertWentBack();
});

it('lists linked tickets from the same order and checks them in as a group', function () {
    $scanned = doorAttendee($this->site, $this->event, ['wp_order_id' => 7001, 'holder_name' => 'Tom Haverford']);
    $mate = doorAttendee($this->site, $this->event, ['wp_order_id' => 7001, 'holder_name' => 'Ben Wyatt']);
    $refunded = doorAttendee($this->site, $this->event, ['wp_order_id' => 7001, 'holder_name' => 'Jean-Ralphio', 'order_status' => 'refunded']);
    doorAttendee($this->site, $this->event, ['wp_order_id' => 7002, 'holder_name' => 'April Ludgate']); // other order

    $screen = scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($scanned), 'format' => 'qr'])
        ->assertSet('phase', 'green')
        ->assertSee('GROUP OF 3')
        ->assertSee('Ben Wyatt')
        ->assertSee('Jean-Ralphio')
        ->assertDontSee('April Ludgate');

    $screen->call('checkinGroup');

    expect($mate->fresh()->checked_in)->toBeTrue()
        ->and($refunded->fresh()->checked_in)->toBeFalse()
        ->and(CheckinOperation::count())->toBe(2); // scanned + eligible mate only
});

it('keeps GREEN on screen while linked tickets remain, then auto-dismisses when done', function () {
    $scanned = doorAttendee($this->site, $this->event, ['wp_order_id' => 7003]);
    $mate = doorAttendee($this->site, $this->event, ['wp_order_id' => 7003]);

    $screen = scanScreen($this->event)
        ->emitNative(CodeScanned::class, ['data' => ticketQrUrl($scanned), 'format' => 'qr'])
        ->assertSet('phase', 'green');

    $screen->set('resultShownAt', microtime(true) - 10)
        ->call('tick')->assertSet('phase', 'green'); // eligible mate holds the card

    $screen->call('checkinMate', $mate->id)
        ->set('resultShownAt', microtime(true) - 10)
        ->call('tick')->assertSet('phase', 'scanning');
});

it('scan tab re-reads the active site on resume after a switch', function () {
    Site::factory()->create(['name' => 'First Site', 'is_active' => true]);
    $second = Site::factory()->create(['name' => 'Second Site', 'base_url' => 'https://second.test', 'is_active' => false]);

    $screen = Native::test(ScanScreen::class)
        ->assertSet('contextLabel', 'All events · First Site');

    $second->activate();

    $screen->call('onResume')
        ->assertSet('contextLabel', 'All events · Second Site');
});

it('group mates never cross event boundaries', function () {
    $site = Site::factory()->create();
    $a = Attendee::factory()->create(['site_id' => $site->id, 'wp_event_id' => 501, 'wp_order_id' => 8000]);
    Attendee::factory()->create(['site_id' => $site->id, 'wp_event_id' => 502, 'wp_order_id' => 8000]);
    $sameEvent = Attendee::factory()->create(['site_id' => $site->id, 'wp_event_id' => 501, 'wp_order_id' => 8000]);

    expect($a->groupMates()->pluck('id')->all())->toBe([$sameEvent->id]);
});

it('scanner start reports failure for decoded array error results', function () {
    Native::fakeBridge()->respondTo('Scanner.Scan', ['status' => 'error', 'code' => 'NO_DEVICE']);

    expect(app(NativeScanner::class)->start('test', 'Scan'))->toBeFalse();
});
