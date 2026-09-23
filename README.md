# TEC Ticket Scanner

A native iOS/Android app that checks in Event Tickets (The Events Calendar) attendees at the door. Point the phone at a ticket QR code and get an instant answer: **green** (valid, checked in), **amber** (already checked in, shows who and when), or **red** (invalid, shows why).

Built with [NativePHP for Mobile v4](https://nativephp.com/docs/mobile/4/) (Laravel 13 running on-device, native SwiftUI/Compose UI) and a small companion WordPress plugin.

## Why

Event Tickets sells the tickets, but door check-in on the free plugin means a browser tab, a live connection, and a search box. That falls apart the moment the venue's Wi‑Fi does, and a queue of 300 people does not wait for a page reload.

This app moves the decision onto the phone:

- **Offline-first.** The full attendee list for an event syncs to on-device SQLite. Every scan validates locally in milliseconds, with or without signal. Check-ins queue and sync back when the network returns.
- **Instant, unambiguous verdicts.** Full-screen green / amber / red with haptic feedback, readable at arm's length in a dark doorway. Amber is a distinct state so staff can spot a duplicated ticket without it looking like a fraud alert.
- **Multi-device safe.** Check-ins are idempotent and attributed per device. If two scanners hit the same ticket, the second gets amber with "checked in by Door 2 at 7:04 pm", not a silent overwrite.
- **Works with the free Event Tickets plugin.** No Event Tickets Plus requirement. Tickets Commerce and RSVP attendees are supported.
- **Secure by default.** Authenticates with WordPress Application Passwords over HTTPS. Credentials live in the device Keychain/Keystore, never in the app database or logs.

## What it does

- **Scan.** Continuous QR scanning, duplicate-read debounce, torch, haptics. Green auto-dismisses so the line keeps moving.
- **Search and manual check-in.** Find an attendee by name or email when a ticket is lost or the phone screen is cracked. Check in with a tap.
- **Undo.** Reverse a mistaken check-in behind a confirmation dialog.
- **Live stats.** Total vs. checked in, broken down by ticket type. Server truth when online, local numbers when not.
- **Multiple sites and events.** Pair one or more WordPress sites and switch between their events.
- **Pairing by QR.** Scan a single-use pairing code from the WordPress admin instead of typing a URL and password at the door.

## How it works

```
Phone (this repo)                              WordPress site
NativePHP v4 app, SQLite, native UI   HTTPS    Companion plugin, REST ns tec-scanner/v1
  Scanner ─▶ QrParser ─▶ ScanValidator ◀────▶  GET  /me
  CheckinService ─▶ checkin_operations  Basic  GET  /events
  SyncEngine ─▶ HttpApiClient           auth   GET  /events/{id}/attendees?updated_since
  SecureStorage (app password per site)        POST /checkins  (batch, idempotent)
                                               GET  /events/{id}/stats
                                               POST /pair
```

The scan decision is a local lookup of the QR's attendee ID, event ID and security code against SQLite. Sync pulls attendees by delta (the plugin tracks check-in changes that Event Tickets itself does not timestamp) and pushes queued check-ins in idempotent batches.

## Requirements

- **Server:** WordPress with Event Tickets (free, 5.7+) and the companion plugin `wp-tec-ticket-scanner`, reachable over HTTPS. A WordPress user with check-in rights and an Application Password (or use QR pairing).
- **Development:** macOS with Xcode 26+ for iOS, PHP 8.3+, Composer, a NativePHP Ultra license (for the premium Scanner and SecureStorage plugins).

## Development

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan test                 # Pest, sqlite :memory:, fixture API client
php artisan native:install ios   # once
LANG=en_US.UTF-8 LC_ALL=en_US.UTF-8 php artisan native:run ios <simulator-udid> --no-tty
```

Key `.env` switches:

| Variable | Purpose |
|---|---|
| `TICKETSCANNER_API` | `http` for a real site, `fixture` to run against `docs/api/fixtures/` |
| `TICKETSCANNER_CA_BUNDLE` | Private CA bundle for local `*.test` sites (on-device PHP ignores the OS keychain) |
| `NATIVEPHP_APP_VERSION` | Keep `DEBUG` for dev builds so the shell re-extracts the bundle on every launch |

The REST contract both codebases test against lives in [docs/api/openapi.yaml](docs/api/openapi.yaml); the ticket and pairing QR formats are in [docs/api/qr-format.md](docs/api/qr-format.md). See [PLAN.md](PLAN.md) for the staged build plan and [CLAUDE.md](CLAUDE.md) for agent orientation and environment gotchas.

## Status

Pre-release. Scanning, sync, search, manual check-in, undo and stats are implemented and tested against a real WordPress site. Settings screen, app icon, Android build and store packaging are in progress.
