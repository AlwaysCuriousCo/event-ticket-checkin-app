<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive only — NativePHP replays migrations on every app launch.
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('attendees', 'wp_order_id')) {
            Schema::table('attendees', function (Blueprint $table) {
                // Attendees purchased together share an order — the group
                // check-in key. Null for RSVP (no order concept).
                $table->unsignedBigInteger('wp_order_id')->nullable()->after('wp_ticket_id');
                $table->index(['site_id', 'wp_order_id']);
            });
        }

        if (! Schema::hasColumn('events', 'allow_walkup')) {
            Schema::table('events', function (Blueprint $table) {
                // Organizer policy from the site: whether door staff may send
                // walk-ups to the registration page.
                $table->boolean('allow_walkup')->default(true)->after('venue');
            });
        }
    }

    public function down(): void
    {
        // Additive-only policy: no rollback on device databases.
    }
};
