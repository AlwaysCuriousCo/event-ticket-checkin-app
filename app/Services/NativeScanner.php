<?php

namespace App\Services;

use Native\Mobile\Events\Scanner\CodeScanned;

/**
 * Starts the native QR scanner AND reports whether it actually started.
 *
 * The vendor PendingScanner discards the bridge result, so a build without
 * the premium Scanner plugin (bridge answers FUNCTION_NOT_FOUND) looks
 * exactly like a working scan — a silent dead end. This calls the bridge
 * directly with the same payload and inspects the answer.
 */
class NativeScanner
{
    public function start(string $id, string $prompt, bool $continuous = false): bool
    {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $result = nativephp_call('Scanner.Scan', json_encode([
            'prompt' => $prompt,
            'continuous' => $continuous,
            'formats' => ['qr'],
            'id' => $id,
            'event' => CodeScanned::class,
        ]));

        $decoded = is_string($result) ? json_decode($result, true) : null;

        // No/odd payload back from a registered function still counts as
        // started; only an explicit bridge error (FUNCTION_NOT_FOUND,
        // NO_DEVICE, permission denial) means the camera never opened.
        return ! (is_array($decoded) && ($decoded['status'] ?? null) === 'error');
    }
}
