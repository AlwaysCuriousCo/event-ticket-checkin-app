<?php

namespace App\Services;

use App\Models\AppSetting;
use Native\Mobile\Facades\Device;
use Throwable;

/**
 * Stable identifier sent as `device_id` with check-in batches, so other
 * devices (and wp-admin) can show who performed a check-in. The friendly
 * name set on the Profile screen wins, then the config default, then the
 * native device id.
 */
class DeviceIdentity
{
    public const SETTING_KEY = 'device_name';

    public function id(): string
    {
        if ($name = AppSetting::get(self::SETTING_KEY)) {
            return $name;
        }

        if ($name = config('ticketscanner.device_name')) {
            return $name;
        }

        try {
            $id = Device::getId();

            if (is_string($id) && $id !== '') {
                return $id;
            }
        } catch (Throwable) {
            // Native bridge absent (dev machine / tests) — fall through.
        }

        return 'dev-device';
    }
}
