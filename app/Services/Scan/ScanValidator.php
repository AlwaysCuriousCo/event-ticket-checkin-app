<?php

namespace App\Services\Scan;

use App\Models\Attendee;
use App\Models\Event;
use App\Models\Site;
use App\Services\Qr\PairingQr;
use App\Services\Qr\TicketQr;

/**
 * Pure decision logic for a scan against the local SQLite copy: no writes,
 * no network. Callers (scan screen / CheckinService) apply side effects.
 *
 * Decision table (see PLAN.md):
 *   GREEN  attendee found for the active event, security code matches,
 *          order completed, not yet checked in
 *   AMBER  same, but already checked in
 *   RED    everything else, with a specific reason
 *
 * Order of checks matters: unknown → wrong event → code mismatch → order
 * status → duplicate. A code mismatch on a checked-in attendee must read as
 * RED (potential forgery), not AMBER.
 */
class ScanValidator
{
    /**
     * Any-event mode (the Scan tab): no event is in context, so the ticket
     * itself names the event. The attendee still has to belong to the
     * connected site, and the event has to be on the device — without a
     * local copy there is nothing to check the ticket against, and a
     * check-in would have nowhere to queue.
     */
    public function validateForSite(TicketQr|PairingQr|null $parsed, Site $site): ScanResult
    {
        if (! $parsed instanceof TicketQr) {
            return new ScanResult(ScanOutcome::Red, ScanReason::NotATicket);
        }

        $event = Event::query()
            ->where('site_id', $site->id)
            ->where('wp_event_id', $parsed->eventId)
            ->first();

        if (! $event) {
            return new ScanResult(ScanOutcome::Red, ScanReason::EventNotOnDevice);
        }

        return $this->validate($parsed, $event);
    }

    public function validate(TicketQr|PairingQr|null $parsed, Event $activeEvent): ScanResult
    {
        if (! $parsed instanceof TicketQr) {
            return new ScanResult(ScanOutcome::Red, ScanReason::NotATicket);
        }

        $attendee = Attendee::query()
            ->where('site_id', $activeEvent->site_id)
            ->where('wp_attendee_id', $parsed->attendeeId)
            ->first();

        if (! $attendee) {
            return new ScanResult(ScanOutcome::Red, ScanReason::UnknownAttendee, event: $activeEvent);
        }

        if ($attendee->wp_event_id !== $activeEvent->wp_event_id
            || $parsed->eventId !== $activeEvent->wp_event_id) {
            return new ScanResult(ScanOutcome::Red, ScanReason::WrongEvent, $attendee, $activeEvent);
        }

        if (! hash_equals($attendee->security_code, $parsed->securityCode)) {
            return new ScanResult(ScanOutcome::Red, ScanReason::SecurityCodeMismatch, $attendee, $activeEvent);
        }

        if (! $attendee->isEligibleForCheckin()) {
            return new ScanResult(ScanOutcome::Red, ScanReason::OrderNotComplete, $attendee, $activeEvent);
        }

        if ($attendee->checked_in) {
            return new ScanResult(ScanOutcome::Amber, ScanReason::AlreadyCheckedIn, $attendee, $activeEvent);
        }

        return new ScanResult(ScanOutcome::Green, ScanReason::Valid, $attendee, $activeEvent);
    }
}
