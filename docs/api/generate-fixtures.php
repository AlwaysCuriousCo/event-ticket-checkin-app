<?php

/**
 * Deterministic fixture generator for the TEC Scanner Companion API contract.
 *
 * Usage: php docs/api/generate-fixtures.php
 * Writes docs/api/fixtures/*.json. Output is stable across runs (fixed seed data,
 * fixed timestamps) so diffs are meaningful. Regenerate after any contract change
 * and commit the output. The companion WP plugin repo copies these files for its
 * golden/contract tests — keep this script the single source of truth.
 */

declare(strict_types=1);

const EVENT_ID = 501;
const SERVER_TIME = '2026-08-11T16:20:00Z';
const SITE_URL = 'https://example.test';

$ticketTypes = [
    701 => ['name' => 'General Admission', 'provider' => 'tickets-commerce', 'count' => 30],
    702 => ['name' => 'VIP',               'provider' => 'tickets-commerce', 'count' => 10],
    703 => ['name' => 'RSVP',              'provider' => 'rsvp',             'count' => 10],
];

$firstNames = ['Ada', 'Grace', 'Alan', 'Edsger', 'Barbara', 'Donald', 'Radia', 'Vint', 'Tim', 'Margaret',
    'Katherine', 'John', 'Dennis', 'Ken', 'Bjarne', 'Anders', 'Brendan', 'Guido', 'Rasmus', 'Taylor',
    'Linus', 'James', 'Yukihiro', 'Rich', 'Joe', 'Robert', 'Martin', 'Kent', 'Ward', 'Erich',
    'Sandi', 'Kathleen', 'Frances', 'Jean', 'Betty', 'Marlyn', 'Ruth', 'Adele', 'Evelyn', 'Ida',
    'Mary', 'Annie', 'Dorothy', 'Klara', 'Hedy', 'Karen', 'Susan', 'Sophie', 'Emmy', 'Lise'];
$lastNames = ['Lovelace', 'Hopper', 'Turing', 'Dijkstra', 'Liskov', 'Knuth', 'Perlman', 'Cerf', 'Berners-Lee', 'Hamilton',
    'Johnson', 'McCarthy', 'Ritchie', 'Thompson', 'Stroustrup', 'Hejlsberg', 'Eich', 'van Rossum', 'Lerdorf', 'Otwell',
    'Torvalds', 'Gosling', 'Matsumoto', 'Hickey', 'Armstrong', 'Griesemer', 'Fowler', 'Beck', 'Cunningham', 'Gamma',
    'Metz', 'Booth', 'Allen', 'Bartik', 'Holberton', 'Meltzer', 'Teitelbaum', 'Goldstine', 'Boyd', 'Rhodes',
    'Shaw', 'Easley', 'Vaughan', 'von Neumann', 'Lamarr', 'Jones', 'Kare', 'Wilson', 'Noether', 'Meitner'];

/** Deterministic 8-hex security code per attendee id (mirrors ET's 8-char codes). */
function securityCode(int $attendeeId): string
{
    return substr(md5('event-ticket-scanner-fixture-'.$attendeeId), 0, 8);
}

$attendees = [];
$attendeeId = 9001;
$i = 0;
foreach ($ticketTypes as $ticketId => $type) {
    for ($n = 0; $n < $type['count']; $n++, $i++, $attendeeId++) {
        $name = $firstNames[$i].' '.$lastNames[$i];
        $email = strtolower(str_replace([' ', '.', "'"], ['', '', ''], $firstNames[$i].'.'.$lastNames[$i])).'@example.test';

        // Deterministic case distribution:
        //  - attendee 9004: refunded order (RED case)
        //  - attendee 9007: pending order (RED case)
        //  - RSVP attendee 9047: denied ("not going") (RED case)
        //  - every 3rd of the first 40 TC attendees checked in (13 TC) + first RSVP (1) = 14 checked in
        //  - attendee 9002: checked in by ANOTHER device (two-device duplicate/AMBER case)
        $orderStatus = match ($attendeeId) {
            9004 => 'refunded',
            9007 => 'pending',
            9047 => 'denied',
            default => 'completed',
        };

        $checkedIn = $orderStatus === 'completed'
            && (($type['provider'] === 'tickets-commerce' && $attendeeId % 3 === 2) || $attendeeId === 9041);

        $checkedInAt = $checkedIn ? sprintf('2026-08-11T15:%02d:00Z', 10 + ($i % 45)) : null;
        $checkedInBy = $checkedIn ? ($attendeeId === 9002 ? 'front-door-ipad' : 'box-office') : null;

        $attendees[] = [
            'id' => $attendeeId,
            'event_id' => EVENT_ID,
            'ticket_id' => $ticketId,
            'ticket_name' => $type['name'],
            'provider' => $type['provider'],
            'holder_name' => $name,
            'holder_email' => $email,
            'security_code' => securityCode($attendeeId),
            // Orders group 4 consecutive TC/Woo attendees (group check-in);
            // RSVP has no order concept.
            'order_id' => $type['provider'] === 'rsvp' ? null : 8000 + intdiv($i, 4),
            'order_status' => $orderStatus,
            'checked_in' => $checkedIn,
            'checked_in_at' => $checkedInAt,
            'checked_in_by' => $checkedInBy,
            'updated_at' => $checkedIn ? $checkedInAt : '2026-08-01T15:04:05Z',
        ];
    }
}

$checkedInCount = count(array_filter($attendees, fn ($a) => $a['checked_in']));

$byType = [];
foreach ($ticketTypes as $ticketId => $type) {
    $ofType = array_filter($attendees, fn ($a) => $a['ticket_id'] === $ticketId);
    $byType[] = [
        'ticket_id' => $ticketId,
        'name' => $type['name'],
        'total' => count($ofType),
        'checked_in' => count(array_filter($ofType, fn ($a) => $a['checked_in'])),
    ];
}

$fixtures = [
    'me.json' => [
        'site_name' => 'Fixture Fest Productions',
        'site_url' => SITE_URL,
        'user' => ['id' => 2, 'login' => 'doorstaff', 'display_name' => 'Door Staff'],
        'capabilities' => ['can_checkin' => true, 'scan_all_events' => false],
        'assigned_event_ids' => [EVENT_ID],
        'plugin_version' => '1.0.0',
        'event_tickets_version' => '5.29.2.1',
        'providers' => ['tickets-commerce', 'rsvp'],
    ],
    'events.json' => [
        'events' => [[
            'allow_walkup' => true,
            'id' => EVENT_ID,
            'title' => 'Fixture Fest 2026',
            'start_date' => '2026-09-12T18:00:00',
            'end_date' => '2026-09-12T23:00:00',
            'timezone' => 'America/Chicago',
            'venue' => 'The Grand Hall',
            'attendee_count' => count($attendees),
            'checked_in_count' => $checkedInCount,
        ]],
        'total' => 1,
        'page' => 1,
        'per_page' => 50,
        'has_more' => false,
    ],
    // Full sync (no updated_since).
    'attendees.json' => [
        'attendees' => $attendees,
        'total' => count($attendees),
        'page' => 1,
        'per_page' => 100,
        'has_more' => false,
        'server_time' => SERVER_TIME,
    ],
    // Delta sync: only rows touched after 2026-08-11T15:00:00Z (i.e. today's check-ins).
    'attendees-delta.json' => (function () use ($attendees) {
        $delta = array_values(array_filter($attendees, fn ($a) => $a['updated_at'] > '2026-08-11T15:00:00Z'));

        return [
            'attendees' => $delta,
            'total' => count($delta),
            'page' => 1,
            'per_page' => 100,
            'has_more' => false,
            'server_time' => SERVER_TIME,
        ];
    })(),
    // Batch response covering every result status the app must reconcile.
    'checkins-response.json' => [
        'results' => [
            [ // ok: fresh check-in of 9001
                'op_id' => '3b2417e6-6a3f-4a3f-9a0e-6b1a2c3d4e5f',
                'status' => 'ok',
                'attendee' => array_merge($attendees[0], [
                    'checked_in' => true,
                    'checked_in_at' => '2026-09-12T18:03:11Z',
                    'checked_in_by' => 'tims-iphone-15',
                    'updated_at' => '2026-09-12T18:03:12Z',
                ]),
            ],
            [ // already_checked_in: 9002 was checked in by front-door-ipad
                'op_id' => '9c1d2e3f-4a5b-4c6d-8e9f-0a1b2c3d4e5f',
                'status' => 'already_checked_in',
                'message' => 'Checked in at 2026-08-11T15:11:00Z by front-door-ipad.',
                'attendee' => $attendees[1],
            ],
            [ // not_authorized: 9004 is refunded
                'op_id' => '1f2e3d4c-5b6a-4798-8899-aabbccddeeff',
                'status' => 'not_authorized',
                'message' => 'Order status is refunded; attendee is not eligible for check-in.',
                'attendee' => $attendees[3],
            ],
            [ // not_found
                'op_id' => '0a1b2c3d-4e5f-4677-8899-001122334455',
                'status' => 'not_found',
                'message' => 'No attendee with ID 99999.',
                'attendee' => null,
            ],
        ],
        'server_time' => '2026-09-12T18:03:12Z',
    ],
    'pair.json' => [
        'site_name' => 'Example Event Site',
        'site_url' => SITE_URL,
        'username' => 'doorstaff',
        'app_password' => 'abcd efgh ijkl mnop abcd efgh',
    ],

    'stats.json' => [
        'event_id' => EVENT_ID,
        'total' => count($attendees),
        'checked_in' => $checkedInCount,
        'by_ticket_type' => $byType,
        'server_time' => SERVER_TIME,
    ],
];

$dir = __DIR__.'/fixtures';
if (! is_dir($dir)) {
    mkdir($dir, 0755, true);
}
foreach ($fixtures as $file => $payload) {
    file_put_contents(
        $dir.'/'.$file,
        json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n"
    );
    echo "wrote fixtures/{$file}\n";
}

// Sample QR payload strings for qr-format.md / manual scan testing.
$qrSamples = [];
foreach ([9001, 9002, 9004] as $id) {
    $qrSamples[] = SITE_URL.'/?'.http_build_query([
        'event_qr_code' => 1,
        'ticket_id' => $id, // NB: Event Tickets puts the ATTENDEE post ID in `ticket_id`
        'event_id' => EVENT_ID,
        'security_code' => securityCode($id),
        'path' => '/wp-json/tribe/tickets/v1/qr',
    ]);
}
// Pairing QR (wp-admin → Tickets → Scanner App).
$qrSamples[] = json_encode([
    'v' => 1,
    'type' => 'event-ticket-scanner-pair',
    'url' => SITE_URL,
    'user' => 'doorstaff',
    'token' => str_repeat('a1b2c3d4', 5),
], JSON_UNESCAPED_SLASHES);

file_put_contents($dir.'/qr-samples.txt', implode("\n", $qrSamples)."\n");
echo "wrote fixtures/qr-samples.txt\n";
