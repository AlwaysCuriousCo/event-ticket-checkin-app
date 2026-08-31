<?php

use App\Models\Site;
use App\NativeComponents\ConnectSite;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use App\Services\Api\FixtureApiClient;
use Native\Mobile\Testing\Native;

covers(ConnectSite::class);

beforeEach(function () {
    $this->bridge = Native::fakeBridge();
    $this->bridge->respondTo('SecureStorage.Set', ['status' => 'ok']);
    $this->bridge->respondTo('SecureStorage.Delete', ['status' => 'ok']);
});

it('renders the connect form', function () {
    Native::test(ConnectSite::class)
        ->assertSee('Site address')
        ->assertSee('Application password');
});

it('rejects an empty or invalid site url', function () {
    Native::test(ConnectSite::class)
        ->call('connect')
        ->assertSee('full https:// URL')
        ->set('siteUrl', 'https://example.test')
        ->call('connect')
        ->assertSee('Username and application password are required');

    expect(Site::count())->toBe(0);
});

it('rejects plain http outside debug builds', function () {
    config(['app.debug' => false]);

    Native::test(ConnectSite::class)
        ->set('siteUrl', 'http://insecure.example')
        ->set('username', 'doorstaff')
        ->set('password', 'abcd efgh')
        ->call('connect')
        ->assertSee('full https:// URL');
});

it('connects, verifies via /me, stores the password securely, and navigates to events', function () {
    Native::test(ConnectSite::class)
        ->set('siteUrl', 'example.test')            // scheme added automatically
        ->set('username', 'doorstaff')
        ->set('password', 'abcd efgh ijkl mnop')
        ->call('connect')
        ->assertSet('error', '')
        ->assertSet('password', '')                 // scrubbed after use
        ->assertReplacedWith('/events');

    $site = Site::sole();
    expect($site->base_url)->toBe('https://example.test')
        ->and($site->name)->toBe('Fixture Fest Productions') // from /me
        ->and($site->last_verified_at)->not->toBeNull();

    // Password went to SecureStorage under the site-scoped key — never the DB.
    $this->bridge->assertCalled('SecureStorage.Set', fn ($p) => ($p['key'] ?? null) === $site->credentialKey());
});

it('rolls back the site and stored password when verification fails', function () {
    app(ApiClient::class)->failNextWith(new ApiException('Invalid credentials', 401));

    Native::test(ConnectSite::class)
        ->set('siteUrl', 'https://example.test')
        ->set('username', 'doorstaff')
        ->set('password', 'wrong')
        ->call('connect')
        ->assertSee('rejected these credentials')
        ->assertNoNavigation();

    expect(Site::count())->toBe(0);
    $this->bridge->assertCalled('SecureStorage.Delete');
});

it('reports a missing companion plugin distinctly', function () {
    app(ApiClient::class)->failNextWith(new ApiException('Not Found', 404));

    Native::test(ConnectSite::class)
        ->set('siteUrl', 'https://example.test')
        ->set('username', 'doorstaff')
        ->set('password', 'abcd')
        ->call('connect')
        ->assertSee('companion plugin');
});

it('refuses to connect the same site and user twice', function () {
    Site::factory()->create(['base_url' => 'https://example.test', 'username' => 'doorstaff']);

    Native::test(ConnectSite::class)
        ->set('siteUrl', 'https://example.test')
        ->set('username', 'doorstaff')
        ->set('password', 'abcd')
        ->call('connect')
        ->assertSee('already connected');

    expect(Site::count())->toBe(1);
});

it('uses the fixture client by default in this stage', function () {
    expect(app(ApiClient::class))->toBeInstanceOf(FixtureApiClient::class);
});

it('pairs a site from a scanned pairing QR', function () {
    $qr = json_encode([
        'v' => 1,
        'type' => 'event-ticket-scanner-pair',
        'url' => 'https://example.test',
        'user' => 'doorstaff',
        'token' => str_repeat('a1b2c3d4', 5),
    ]);

    Native::test(ConnectSite::class)
        ->call('onCodeScanned', $qr, 'qr')
        ->assertReplacedWith('/events');

    $site = Site::sole();
    expect($site->base_url)->toBe('https://example.test')
        ->and($site->username)->toBe('doorstaff')
        ->and($site->last_verified_at)->not->toBeNull();
});

it('falls back to the manual form for a legacy pairing QR', function () {
    Native::test(ConnectSite::class)
        ->call('onCodeScanned', json_encode(['url' => 'https://example.test']), 'qr')
        ->assertSee('no pairing code')
        ->assertSet('siteUrl', 'https://example.test');

    expect(Site::count())->toBe(0);
});

it('reports an expired pairing token', function () {
    app(ApiClient::class)->failNextWith(new ApiException('nope', 403));

    $qr = json_encode([
        'type' => 'event-ticket-scanner-pair',
        'url' => 'https://example.test',
        'token' => str_repeat('a1b2c3d4', 5),
    ]);

    Native::test(ConnectSite::class)
        ->call('onCodeScanned', $qr, 'qr')
        ->assertSee('invalid or has expired');

    expect(Site::count())->toBe(0);
});

it('rejects a ticket QR on the connect screen', function () {
    Native::test(ConnectSite::class)
        ->call('onCodeScanned', 'https://example.test/?ticket_id=9001&event_id=501&security_code=abc', 'qr')
        ->assertSee('not a pairing QR');
});

it('offers a way back only when a site is already connected', function () {
    expect(Native::test(ConnectSite::class)->get('canCancel'))->toBeFalse();

    Site::factory()->create();

    expect(Native::test(ConnectSite::class)->get('canCancel'))->toBeTrue();
});

it('activates the newly connected site', function () {
    $old = Site::factory()->create(['name' => 'Old Site', 'base_url' => 'https://old.test', 'is_active' => true]);

    $qr = json_encode([
        'v' => 1,
        'type' => 'event-ticket-scanner-pair',
        'url' => 'https://example.test',
        'user' => 'doorstaff',
        'token' => str_repeat('a1b2c3d4', 5),
    ]);

    Native::test(ConnectSite::class)->call('onCodeScanned', $qr, 'qr');

    expect(Site::current()->base_url)->toBe('https://example.test')
        ->and($old->refresh()->is_active)->toBeFalse();
});

it('rejects re-scanning a pairing QR for an already-connected site', function () {
    // The pair fixture resolves to doorstaff @ https://example.test.
    Site::factory()->create(['base_url' => 'https://example.test', 'username' => 'doorstaff']);

    $qr = json_encode([
        'v' => 1,
        'type' => 'event-ticket-scanner-pair',
        'url' => 'https://example.test',
        'user' => 'doorstaff',
        'token' => str_repeat('a1b2c3d4', 5),
    ]);

    Native::test(ConnectSite::class)
        ->call('onCodeScanned', $qr, 'qr')
        ->assertSee('already connected');

    expect(Site::count())->toBe(1);
});
