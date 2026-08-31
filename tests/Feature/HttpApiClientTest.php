<?php

use App\Models\Site;
use App\Services\Api\HttpApiClient;
use App\Services\SiteCredentials;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Testing\Native;

covers(HttpApiClient::class);

it('asks for past events too, since the server hides them by default', function () {
    $bridge = Native::fakeBridge();
    $bridge->respondTo('SecureStorage.Set', ['status' => 'ok']);

    Http::fake([
        '*' => Http::response(['events' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'has_more' => false]),
    ]);

    $site = Site::factory()->create(['base_url' => 'https://example.test']);
    app(SiteCredentials::class)->store($site, 'app pass word');

    app(HttpApiClient::class)->events($site);

    Http::assertSent(fn ($request) => str_contains($request->url(), 'upcoming=0'));
});
