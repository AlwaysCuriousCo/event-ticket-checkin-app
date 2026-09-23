<?php

use App\Models\Event;

it('formats the start as the event wall clock', function () {
    expect((new Event(['starts_at' => '2026-09-23T19:00:00']))->displayDate())->toBe('Wed, Sep 23 · 7:00 PM')
        ->and((new Event(['starts_at' => null]))->displayDate())->toBe('')
        ->and((new Event(['starts_at' => 'soon']))->displayDate())->toBe('soon');
});
