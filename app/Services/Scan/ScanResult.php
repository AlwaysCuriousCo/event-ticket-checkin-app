<?php

namespace App\Services\Scan;

use App\Models\Attendee;
use App\Models\Event;

final readonly class ScanResult
{
    public function __construct(
        public ScanOutcome $outcome,
        public ScanReason $reason,
        public ?Attendee $attendee = null,
        /** The event the ticket was validated against — the caller's active
         * event, or the one resolved from the ticket in any-event mode. */
        public ?Event $event = null,
    ) {}
}
