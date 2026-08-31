<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive-only (see 2026_08_14_000001). Marks which connected site the app
// is currently working in; Site::current() falls back to the oldest row when
// nothing is flagged, so devices upgrading from a single-site build keep
// working without a data migration.
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('sites', 'is_active')) {
            return;
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('is_active')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
