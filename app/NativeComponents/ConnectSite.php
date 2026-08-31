<?php

namespace App\NativeComponents;

use App\Models\Site;
use App\Services\Api\ApiClient;
use App\Services\Api\ApiException;
use App\Services\DeviceIdentity;
use App\Services\NativeScanner;
use App\Services\Qr\PairingQr;
use App\Services\Qr\QrParser;
use App\Services\SiteCredentials;
use Illuminate\View\View;
use Native\Mobile\Attributes\On;
use Native\Mobile\Edge\NativeComponent;
use Native\Mobile\Events\Scanner\CodeScanned;

class ConnectSite extends NativeComponent
{
    public string $siteUrl = '';

    public string $username = '';

    public string $password = '';

    public string $error = '';

    public bool $busy = false;

    /** Set when the native scanner isn't in this build — hides the QR path. */
    public bool $scannerUnavailable = false;

    /**
     * Reached from Profile → Connect another site, rather than first-run
     * onboarding — so there is somewhere to go back to.
     */
    public bool $canCancel = false;

    public function mount(): void
    {
        $this->canCancel = Site::query()->exists();
    }

    /**
     * Pair by scanning the QR from wp-admin (Tickets → Scanner App). The
     * token in it is single-use and expires in 5 minutes; the server trades
     * it for an Application Password so nobody types credentials at a door.
     */
    public function scanPairingCode(): void
    {
        $this->error = '';

        if (! app(NativeScanner::class)->start('pair-scan', 'Point at the pairing QR in wp-admin')) {
            $this->scannerUnavailable = true;
            $this->error = 'QR scanning is not available in this build — enter the details manually.';
        }
    }

    #[On(CodeScanned::class)]
    public function onCodeScanned(string $data, string $format, ?string $id = null): void
    {
        if ($this->busy) {
            return;
        }

        $parsed = app(QrParser::class)->parse($data);

        if (! $parsed instanceof PairingQr) {
            $this->error = 'That is not a pairing QR. In wp-admin go to Tickets → Scanner App.';

            return;
        }

        // Legacy ET+ QRs carry no token: prefill what we know and let the
        // organizer finish with an application password.
        if (! $parsed->isExchangeable()) {
            $this->siteUrl = $parsed->url;
            $this->username = $parsed->user ?? $this->username;
            $this->error = 'That QR has no pairing code — enter an application password to finish.';

            return;
        }

        $this->pairWith($parsed);
    }

    private function pairWith(PairingQr $qr): void
    {
        $url = $this->normalizeUrl($qr->url);

        if ($url === null) {
            $this->error = 'That pairing QR points at an address this app cannot use.';

            return;
        }

        $this->busy = true;

        try {
            $paired = app(ApiClient::class)->pair($url, $qr->token, app(DeviceIdentity::class)->id());
        } catch (ApiException $e) {
            $this->busy = false;
            $this->error = $e->status === 403
                ? 'This pairing code is invalid or has expired. Generate a fresh one in wp-admin.'
                : "Pairing failed: {$e->getMessage()}";

            return;
        }

        $this->finishConnect(
            $url,
            (string) $paired['username'],
            (string) $paired['app_password'],
            (string) ($paired['site_name'] ?? parse_url($url, PHP_URL_HOST)),
        );
    }

    public function connect(): void
    {
        $this->error = '';

        $url = $this->normalizeUrl($this->siteUrl);

        if ($url === null) {
            $this->error = 'Enter the site address as a full https:// URL.';

            return;
        }

        if (trim($this->username) === '' || trim($this->password) === '') {
            $this->error = 'Username and application password are required.';

            return;
        }

        if (Site::where('base_url', $url)->where('username', trim($this->username))->exists()) {
            $this->error = 'That site is already connected for this user.';

            return;
        }

        $this->finishConnect($url, trim($this->username), trim($this->password));
    }

    /**
     * Shared tail of both paths (typed credentials and scanned pairing):
     * store, verify against /me, keep or roll back.
     */
    private function finishConnect(string $url, string $username, string $password, ?string $name = null): void
    {
        $this->busy = true;

        // The password must be in SecureStorage BEFORE the verify call — the
        // HTTP client reads it from there. Everything rolls back on failure.
        $site = Site::create([
            'name' => $name ?: parse_url($url, PHP_URL_HOST),
            'base_url' => $url,
            'username' => $username,
        ]);

        app(SiteCredentials::class)->store($site, $password);

        try {
            $me = app(ApiClient::class)->me($site);
        } catch (ApiException $e) {
            $this->abortConnect($site, $e->isAuthFailure()
                ? 'The site rejected these credentials. Check the username and application password.'
                : ($e->status === 404
                    ? 'The TEC Scanner companion plugin does not appear to be installed on this site.'
                    : "Could not reach the site: {$e->getMessage()}"));

            return;
        }

        if (! ($me['capabilities']['can_checkin'] ?? false)) {
            $this->abortConnect($site, 'This user is not allowed to manage check-ins. Ask an administrator for the check-in capability.');

            return;
        }

        $site->update(['name' => $me['site_name'], 'last_verified_at' => now()]);

        $this->password = ''; // never keep it in component state longer than needed
        $this->busy = false;

        $this->replace('/events');
    }

    private function abortConnect(Site $site, string $message): void
    {
        app(SiteCredentials::class)->forget($site);
        $site->delete();

        $this->busy = false;
        $this->error = $message;
    }

    /** Require https:// (plain http only in debug builds for local test sites). */
    private function normalizeUrl(string $raw): ?string
    {
        $raw = rtrim(trim($raw), '/');

        if ($raw === '') {
            return null;
        }

        if (! preg_match('#^https?://#i', $raw)) {
            $raw = 'https://'.$raw;
        }

        if (! filter_var($raw, FILTER_VALIDATE_URL)) {
            return null;
        }

        if (str_starts_with(strtolower($raw), 'http://') && ! config('app.debug')) {
            return null;
        }

        return $raw;
    }

    public function navTitle(): string
    {
        return 'Connect a Site';
    }

    public function render(): View
    {
        return view('native.connect-site');
    }
}
