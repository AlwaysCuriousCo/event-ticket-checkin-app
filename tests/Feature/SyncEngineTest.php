<?php

use App\Models\Attendee;
use App\Models\CheckinOperation;
use App\Models\Event;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use App\Services\Api\FixtureApiClient;
use App\Services\Connectivity;
use App\Services\DeviceIdentity;
use App\Services\SyncEngine;
use Illuminate\Support\Str;

covers(SyncEngine::class);

beforeEach(function () {
    $this->event = Event::factory()->create(); // wp_event_id 501, matches fixtures
    $this->site = $this->event->site;
    $this->api = app(ApiClient::class); // FixtureApiClient (singleton, stateful)
    $this->engine = app(SyncEngine::class);
});

function pendingOp(Event $event, int $wpAttendeeId, string $action = 'checkin'): CheckinOperation
{
    return CheckinOperation::create([
        'op_id' => (string) Str::uuid(),
        'site_id' => $event->site_id,
        'wp_attendee_id' => $wpAttendeeId,
        'wp_event_id' => $event->wp_event_id,
        'action' => $action,
        'occurred_at' => '2026-09-12T18:03:11Z',
    ]);
}

it('binds the fixture client by default', function () {
    expect($this->api)->toBeInstanceOf(FixtureApiClient::class);
});

it('performs a full sync of all fixture attendees', function () {
    $count = $this->engine->pullAttendees($this->event);

    expect($count)->toBe(50)
        ->and(Attendee::where('site_id', $this->site->id)->count())->toBe(50)
        ->and(Attendee::where('checked_in', true)->count())->toBe(14)
        ->and($this->event->refresh()->sync_cursor)->not->toBeNull()
        ->and($this->event->last_synced_at)->not->toBeNull();

    $ada = Attendee::where('wp_attendee_id', 9001)->first();
    expect($ada->holder_name)->toBe('Ada Lovelace')
        ->and($ada->checked_in)->toBeFalse()
        ->and($ada->checked_in_source)->toBe(Attendee::SOURCE_SERVER);
});

it('delta sync after a full sync pulls nothing when the server is unchanged', function () {
    $this->engine->pullAttendees($this->event);
    $firstCursor = $this->event->refresh()->sync_cursor;

    $count = $this->engine->pullAttendees($this->event);

    expect($count)->toBe(0)
        ->and($this->event->refresh()->sync_cursor)->toBeGreaterThanOrEqual($firstCursor);
});

it('delta sync picks up server-side changes since the cursor', function () {
    $this->engine->pullAttendees($this->event);

    // Another device checks in Ada (9001) directly against the "server",
    // strictly after the cursor — the suite clock is frozen.
    $this->travel(1)->second();
    $this->api->pushCheckins($this->site, 'front-door-ipad', [[
        'op_id' => (string) Str::uuid(),
        'attendee_id' => 9001,
        'action' => 'checkin',
        'occurred_at' => now()->utc()->toIso8601ZuluString(),
    ]]);

    $count = $this->engine->pullAttendees($this->event);

    expect($count)->toBe(1);
    $ada = Attendee::where('wp_attendee_id', 9001)->first();
    expect($ada->checked_in)->toBeTrue()
        ->and($ada->checked_in_by)->toBe('front-door-ipad');
});

it('preserves local check-in state for attendees with pending operations during pull', function () {
    $this->engine->pullAttendees($this->event);

    // Local (offline) check-in of Ada: local state + pending op.
    $ada = Attendee::where('wp_attendee_id', 9001)->first();
    $ada->update(['checked_in' => true, 'checked_in_source' => Attendee::SOURCE_LOCAL, 'checked_in_by' => 'dev-device']);
    pendingOp($this->event, 9001);

    // Force a full re-sync (server still says Ada is NOT checked in).
    $this->event->update(['sync_cursor' => null]);
    $this->engine->pullAttendees($this->event);

    $ada->refresh();
    expect($ada->checked_in)->toBeTrue()
        ->and($ada->checked_in_source)->toBe(Attendee::SOURCE_LOCAL);
});

it('pushes pending operations and applies per-op results', function () {
    $this->engine->pullAttendees($this->event);

    $okOp = pendingOp($this->event, 9001);        // fresh → ok
    $conflictOp = pendingOp($this->event, 9002);  // fixture: checked in by front-door-ipad

    $confirmed = $this->engine->pushCheckins($this->site);

    expect($confirmed)->toBe(2)
        ->and(CheckinOperation::pending()->count())->toBe(0)
        ->and($okOp->refresh()->result_status)->toBe('ok')
        ->and($okOp->attempts)->toBe(1)
        ->and($conflictOp->refresh()->result_status)->toBe('already_checked_in');

    // The conflict reconciles to the server's winner.
    $grace = Attendee::where('wp_attendee_id', 9002)->first();
    expect($grace->checked_in)->toBeTrue()
        ->and($grace->checked_in_by)->toBe('front-door-ipad')
        ->and($grace->checked_in_source)->toBe(Attendee::SOURCE_SERVER);
});

it('keeps operations pending and counts the attempt when the push fails', function () {
    $op = pendingOp($this->event, 9001);

    $this->api->failNextWith(new ApiException('gateway timeout', 504));

    expect(fn () => $this->engine->pushCheckins($this->site))->toThrow(ApiException::class);

    expect($op->refresh()->synced_at)->toBeNull()
        ->and($op->attempts)->toBe(1)
        ->and($op->result_status)->toBeNull();
});

it('does nothing when offline', function () {
    $offline = new class extends Connectivity
    {
        public function isOnline(): bool
        {
            return false;
        }
    };

    $engine = new SyncEngine($this->api, $offline, app(DeviceIdentity::class));
    pendingOp($this->event, 9001);

    expect($engine->syncEvent($this->event))->toBeFalse()
        ->and(CheckinOperation::pending()->count())->toBe(1)
        ->and($this->event->refresh()->sync_cursor)->toBeNull();
});

it('syncEvent pushes before pulling so local check-ins survive the pull', function () {
    $this->engine->pullAttendees($this->event);

    // Offline check-in of Ada, then connectivity returns.
    $ada = Attendee::where('wp_attendee_id', 9001)->first();
    $ada->update(['checked_in' => true, 'checked_in_source' => Attendee::SOURCE_LOCAL, 'checked_in_by' => 'dev-device']);
    pendingOp($this->event, 9001);

    expect($this->engine->syncEvent($this->event))->toBeTrue();

    $ada->refresh();
    expect($ada->checked_in)->toBeTrue()
        ->and($ada->checked_in_by)->toBe('dev-device')
        ->and($ada->checked_in_source)->toBe(Attendee::SOURCE_SERVER)
        ->and(CheckinOperation::pending()->count())->toBe(0);
});
