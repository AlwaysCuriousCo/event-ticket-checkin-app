<?php

use App\Services\Qr\PairingQr;
use App\Services\Qr\QrParser;
use App\Services\Qr\TicketQr;

covers(QrParser::class);

$parser = fn () => new QrParser;

it('parses a canonical ticket QR url', function () use ($parser) {
    $raw = 'https://example.test/?event_qr_code=1&ticket_id=9001&event_id=501&security_code=662b48bd&path=%2Fwp-json%2Ftribe%2Ftickets%2Fv1%2Fqr';

    $result = $parser()->parse($raw);

    expect($result)->toBeInstanceOf(TicketQr::class)
        ->and($result->attendeeId)->toBe(9001)
        ->and($result->eventId)->toBe(501)
        ->and($result->securityCode)->toBe('662b48bd');
});

it('parses the committed qr-samples fixture lines', function () {
    // dirname() not base_path(): Unit tests run without a booted Laravel app.
    $lines = array_filter(explode("\n", file_get_contents(dirname(__DIR__, 2).'/docs/api/fixtures/qr-samples.txt')));

    foreach ($lines as $line) {
        // Ticket URLs plus the one pairing QR the generator appends last.
        $expected = str_starts_with($line, '{') ? PairingQr::class : TicketQr::class;

        expect((new QrParser)->parse($line))->toBeInstanceOf($expected);
    }
});

it('tolerates reordered and extra query args and a different host', function () use ($parser) {
    $raw = 'http://tickets.other-host.example/checkin?foo=bar&security_code=abc123&event_id=77&utm_source=email&ticket_id=42';

    $result = $parser()->parse($raw);

    expect($result)->toBeInstanceOf(TicketQr::class)
        ->and($result->attendeeId)->toBe(42)
        ->and($result->eventId)->toBe(77)
        ->and($result->securityCode)->toBe('abc123');
});

it('rejects ticket urls with missing or malformed args', function (string $raw) use ($parser) {
    expect($parser()->parse($raw))->toBeNull();
})->with([
    'missing security_code' => 'https://example.test/?ticket_id=9001&event_id=501',
    'missing ticket_id' => 'https://example.test/?event_id=501&security_code=abc',
    'non-numeric ticket_id' => 'https://example.test/?ticket_id=abc&event_id=501&security_code=abc',
    'non-numeric event_id' => 'https://example.test/?ticket_id=1&event_id=xyz&security_code=abc',
    'empty security_code' => 'https://example.test/?ticket_id=1&event_id=2&security_code=',
    'no query string at all' => 'https://example.test/tickets',
]);

it('parses an app-pairing QR json payload', function () use ($parser) {
    $raw = '{"url":"https://example.test","api_key":"1a2b3c4d","tec":true}';

    $result = $parser()->parse($raw);

    expect($result)->toBeInstanceOf(PairingQr::class)
        ->and($result->url)->toBe('https://example.test');
});

it('rejects foreign QR payloads', function (string $raw) use ($parser) {
    expect($parser()->parse($raw))->toBeNull();
})->with([
    'plain text' => 'hello world',
    'wifi qr' => 'WIFI:S:venue-wifi;T:WPA;P:secret;;',
    'vcard' => "BEGIN:VCARD\nVERSION:3.0\nFN:Ada\nEND:VCARD",
    'json without url' => '{"api_key":"1a2b3c4d"}',
    'json with invalid url' => '{"url":"not a url"}',
    'empty string' => '   ',
]);
