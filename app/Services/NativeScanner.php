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

        // Simulators have no camera: the native scanner view opens, fails,
        // and its dismissal pops whatever screen is on top of the nav stack
        // — even a screen pushed after the fact. Refuse to start instead;
        // callers already render a graceful "scanner unavailable" state.
        if ($this->deviceIsVirtual()) {
            return false;
        }

        $result = self::decode(nativephp_call('Scanner.Scan', json_encode([
            'prompt' => $prompt,
            'continuous' => $continuous,
            'formats' => ['qr'],
            'id' => $id,
            'event' => CodeScanned::class,
        ])));

        // No/odd payload back from a registered function still counts as
        // started; only an explicit bridge error (FUNCTION_NOT_FOUND,
        // NO_DEVICE, permission denial) means the camera never opened.
        return ! (is_array($result) && ($result['status'] ?? null) === 'error');
    }

    private function deviceIsVirtual(): bool
    {
        $info = self::decode(nativephp_call('Device.GetInfo', '{}'));

        return (bool) ($info['isVirtual'] ?? false);
    }

    /**
     * The dev bridge returns already-decoded arrays/objects; a real
     * device returns JSON strings. Normalize both before probing.
     */
    private static function decode(mixed $result): ?array
    {
        return match (true) {
            is_string($result) => json_decode($result, true),
            is_array($result) => $result,
            is_object($result) => (array) $result,
            default => null,
        };
    }
}
