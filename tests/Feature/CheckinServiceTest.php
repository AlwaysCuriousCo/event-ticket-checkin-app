<?php

use App\Jobs\SyncEventJob;
use App\Models\Attendee;
use App\Models\CheckinOperation;
use App\Models\Event;
use App\Services\Api\ApiException;
use App\Services\CheckinService;
use App\Services\SyncEngine;
use Illuminate\Support\Facades\Queue;

covers(CheckinService::class);

beforeEach(function () {
    Queue::fake();
    $this->event = Event::factory()->create();
    $this->attendee = Attendee::factory()->create([
        'site_id' => $this->event->site_id,
        'wp_event_id' => $this->event->wp_event_id,
    ]);
    $this->service = app(CheckinService::class);
});

it('checks in locally and enqueues exactly one pending operation', function () {
    $this->service->checkin($this->attendee, $this->event);

    $this->attendee->refresh();
    expect($this->attendee->checked_in)->toBeTrue()
        ->and($this->attendee->checked_in_source)->toBe(Attendee::SOURCE_LOCAL)
        ->and($this->attendee->checked_in_by)->toBe('dev-device');

    $ops = CheckinOperation::pending()->get();
    expect($ops)->toHaveCount(1)
        ->and($ops->first()->action)->toBe(CheckinOperation::ACTION_CHECKIN)
        ->and($ops->first()->wp_attendee_id)->toBe($this->attendee->wp_attendee_id)
        ->and($ops->first()->op_id)->toBeUuid();
});

it('debounces sync dispatch across rapid consecutive check-ins', function () {
    $second = Attendee::factory()->create([
        'site_id' => $this->event->site_id,
        'wp_event_id' => $this->event->wp_event_id,
    ]);

    $this->service->checkin($this->attendee, $this->event);
    $this->service->checkin($second, $this->event);

    Queue::assertPushed(SyncEventJob::class, 1);
    expect(CheckinOperation::pending()->count())->toBe(2);
});

it('undo flips local state and enqueues an uncheckin operation', function () {
    $this->service->checkin($this->attendee, $this->event);
    $this->service->undo($this->attendee, $this->event);

    $this->attendee->refresh();
    expect($this->attendee->checked_in)->toBeFalse()
        ->and($this->attendee->checked_in_at)->toBeNull();

    expect(CheckinOperation::pending()->pluck('action')->all())
        ->toBe([CheckinOperation::ACTION_CHECKIN, CheckinOperation::ACTION_UNCHECKIN]);
});

it('uses the configured device name when set', function () {
    config(['ticketscanner.device_name' => 'tims-iphone-15']);

    $this->service->checkin($this->attendee, $this->event);

    expect($this->attendee->refresh()->checked_in_by)->toBe('tims-iphone-15');
});

it('syncs inline instead of queueing when the debounce is zero', function () {
    config(['ticketscanner.sync_debounce_seconds' => 0]);
    $engine = Mockery::mock(SyncEngine::class);
    $engine->shouldReceive('syncEvent')->once()->andReturn(true);
    app()->instance(SyncEngine::class, $engine);

    $this->service->checkin($this->attendee, $this->event);

    Queue::assertNothingPushed();
});

it('falls back to the queued job when the inline sync fails', function () {
    config(['ticketscanner.sync_debounce_seconds' => 0]);
    $engine = Mockery::mock(SyncEngine::class);
    $engine->shouldReceive('syncEvent')->once()->andThrow(new ApiException('down', 503));
    app()->instance(SyncEngine::class, $engine);

    $this->service->checkin($this->attendee, $this->event);

    Queue::assertPushed(SyncEventJob::class, 1);
});
