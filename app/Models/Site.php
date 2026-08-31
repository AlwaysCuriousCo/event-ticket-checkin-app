<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Site extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'base_url', 'username', 'last_verified_at', 'is_active'];

    protected function casts(): array
    {
        return ['last_verified_at' => 'datetime', 'is_active' => 'boolean'];
    }

    /**
     * The site every screen works in. Falls back to the oldest connected
     * site (and promotes it) so devices that connected before multi-site
     * support — or that just deleted their active site — stay usable.
     */
    public static function current(): ?self
    {
        $active = static::query()->where('is_active', true)->first();

        if ($active) {
            return $active;
        }

        $fallback = static::query()->oldest('id')->first();
        $fallback?->activate();

        return $fallback;
    }

    /** Make this the active site, demoting every other one. */
    public function activate(): void
    {
        static::query()->where('id', '!=', $this->id)->update(['is_active' => false]);

        $this->forceFill(['is_active' => true])->save();
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class);
    }

    public function checkinOperations(): HasMany
    {
        return $this->hasMany(CheckinOperation::class);
    }

    /** SecureStorage key holding this site's application password. */
    public function credentialKey(): string
    {
        return "site_{$this->id}_password";
    }

    /** Root of the companion plugin's REST namespace on this site. */
    public function apiBase(): string
    {
        return static::apiBaseFor($this->base_url);
    }

    /** Same root for a site that isn't stored yet (pairing). */
    public static function apiBaseFor(string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/wp-json/event-ticket-scanner/v1';
    }
}
