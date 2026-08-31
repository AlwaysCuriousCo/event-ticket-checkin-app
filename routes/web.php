<?php

use App\NativeComponents\AttendeeDetail;
use App\NativeComponents\AttendeesIndex;
use App\NativeComponents\BrowseScreen;
use App\NativeComponents\ConnectSite;
use App\NativeComponents\EventHome;
use App\NativeComponents\EventsIndex;
use App\NativeComponents\Home;
use App\NativeComponents\ScanScreen;
use App\NativeComponents\SettingsScreen;
use App\NativeComponents\StatsScreen;
use App\NativeComponents\WalkUpScreen;
use App\NativeLayouts\AppLayout;
use Illuminate\Support\Facades\Route;

Route::native('/', Home::class);

// Onboarding stays outside the tab bar — there is nothing to navigate to
// until a site is connected.
Route::native('/connect', ConnectSite::class);

// Everything else lives under the bottom nav. Pushed detail screens still
// declare `$hidesTabBar`; the layout only supplies the bar itself.
Route::nativeGroup(AppLayout::class, function () {
    Route::native('/events', EventsIndex::class);
    Route::native('/events/{event}', EventHome::class);
    Route::native('/events/{event}/attendees', AttendeesIndex::class);
    Route::native('/events/{event}/stats', StatsScreen::class);
    Route::native('/events/{event}/walkup', WalkUpScreen::class);
    Route::native('/attendees/{attendee}', AttendeeDetail::class);
    Route::native('/settings', SettingsScreen::class);
    Route::native('/browse', BrowseScreen::class);

    // Scanner tab: no event in context, the event comes from the ticket.
    Route::native('/scan', ScanScreen::class);
    Route::native('/scan/{event}', ScanScreen::class);
});
