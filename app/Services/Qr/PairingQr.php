<?php

namespace App\Services\Qr;

/**
 * Parsed contents of an app-pairing QR.
 *
 * The companion plugin's QR (wp-admin → Tickets → Scanner App) carries a
 * single-use `token` the app exchanges at `/pair` for an Application
 * Password. The legacy ET+ QR carries only a URL, so `token` is null and
 * the app falls back to prefilling the manual form.
 */
final readonly class PairingQr
{
    public function __construct(
        public string $url,
        public ?string $user = null,
        public ?string $token = null,
    ) {}

    /** Can this QR drive the automatic /pair exchange? */
    public function isExchangeable(): bool
    {
        return $this->token !== null;
    }
}
