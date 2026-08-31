<?php

namespace App\Services\Qr;

class QrParser
{
    /**
     * Parse a raw scanned string into a TicketQr or PairingQr.
     * Returns null for anything that is neither (foreign QR codes).
     */
    public function parse(string $raw): TicketQr|PairingQr|null
    {
        $raw = trim($raw);

        if ($raw === '') {
            return null;
        }

        return $this->parseTicket($raw) ?? $this->parsePairing($raw);
    }

    /**
     * Ticket QRs are URLs carrying `ticket_id` (= attendee post ID), `event_id`
     * and `security_code` query args. The host is NOT validated against the
     * connected site: organizers may connect via a different hostname than the
     * one tickets were issued under; the attendee-ID + security-code lookup is
     * the actual validation.
     */
    private function parseTicket(string $raw): ?TicketQr
    {
        $query = parse_url($raw, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $args);

        $attendeeId = $args['ticket_id'] ?? null;
        $eventId = $args['event_id'] ?? null;
        $securityCode = $args['security_code'] ?? null;

        if (! is_string($attendeeId) || ! ctype_digit($attendeeId)
            || ! is_string($eventId) || ! ctype_digit($eventId)
            || ! is_string($securityCode) || $securityCode === '') {
            return null;
        }

        return new TicketQr((int) $attendeeId, (int) $eventId, $securityCode);
    }

    /** Pairing QRs are JSON objects with at least a `url` key. */
    private function parsePairing(string $raw): ?PairingQr
    {
        if (! str_starts_with($raw, '{')) {
            return null;
        }

        $data = json_decode($raw, true);

        if (! is_array($data) || ! is_string($data['url'] ?? null)) {
            return null;
        }

        $url = trim($data['url']);

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        // v2 (companion plugin): a single-use token the app trades at /pair.
        // Legacy ET+ QRs have no token — only the URL is usable.
        $token = $data['token'] ?? null;
        $user = $data['user'] ?? null;

        return new PairingQr(
            $url,
            is_string($user) && $user !== '' ? $user : null,
            is_string($token) && strlen($token) >= 20 ? $token : null,
        );
    }
}
