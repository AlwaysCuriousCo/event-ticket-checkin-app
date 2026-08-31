<?php

namespace App\Models;

use Carbon\Carbon;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id', 'wp_event_id', 'title', 'starts_at', 'ends_at',
        'timezone', 'venue', 'allow_walkup', 'last_synced_at', 'sync_cursor',
    ];

    protected function casts(): array
    {
        return ['last_synced_at' => 'datetime', 'allow_walkup' => 'boolean'];
    }

    /**
     * Has the event finished? Uses the end time when the site supplied one
     * (a day-long conference stays "upcoming" all day), and interprets both
     * stamps in the event's own timezone — they arrive from WordPress as
     * local wall-clock strings, not UTC.
     */
    public function hasEnded(): bool
    {
        $stamp = $this->ends_at ?: $this->starts_at;

        if (! $stamp) {
            return false; // undated events stay visible rather than vanishing
        }

        // Sites report zones WordPress-style, which includes offsets Carbon
        // rejects outright ("UTC+0", "UTC-5.5"); normalize those to a real
        // offset, and fall back to UTC for anything still unusable.
        $tz = (string) $this->timezone;

        if (preg_match('/^UTC([+-])(\d{1,2})(?:[.:](\d+))?$/', $tz, $m)) {
            $minutes = isset($m[3]) ? (int) round(60 * (float) "0.{$m[3]}") : 0;
            $tz = sprintf('%s%02d:%02d', $m[1], (int) $m[2], $minutes);
        }

        $tz = @timezone_open($tz) ?: config('app.timezone');

        try {
            return Carbon::parse($stamp, $tz)->isPast();
        } catch (InvalidFormatException) {
            return false;
        }
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(Attendee::class, 'wp_event_id', 'wp_event_id')
            ->where('attendees.site_id', $this->site_id);
    }
}
