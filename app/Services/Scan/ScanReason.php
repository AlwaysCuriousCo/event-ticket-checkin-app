<?php

namespace App\Services\Scan;

enum ScanReason: string
{
    // Green
    case Valid = 'valid';

    // Amber
    case AlreadyCheckedIn = 'already_checked_in';

    // Red
    case NotATicket = 'not_a_ticket';
    case UnknownAttendee = 'unknown_attendee';
    case WrongEvent = 'wrong_event';
    case SecurityCodeMismatch = 'security_code_mismatch';
    case OrderNotComplete = 'order_not_complete';
    case EventNotOnDevice = 'event_not_on_device';

    public function label(): string
    {
        return match ($this) {
            self::Valid => 'Valid ticket',
            self::AlreadyCheckedIn => 'Already checked in',
            self::NotATicket => 'Not a ticket QR',
            self::UnknownAttendee => 'Unknown ticket',
            self::WrongEvent => 'Ticket is for a different event',
            self::SecurityCodeMismatch => 'Security code does not match',
            self::OrderNotComplete => 'Order not complete',
            self::EventNotOnDevice => 'That event is not downloaded to this device',
        };
    }
}
