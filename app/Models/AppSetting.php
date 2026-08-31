<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Device-local preferences (Profile screen). Not for secrets — application
 * passwords live in SecureStorage / device_secrets via SiteCredentials.
 */
class AppSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['key', 'value'];

    public static function get(string $key, ?string $default = null): ?string
    {
        $value = static::query()->find($key)?->value;

        return ($value === null || $value === '') ? $default : $value;
    }

    public static function put(string $key, ?string $value): void
    {
        static::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
