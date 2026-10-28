<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** "Coming soon" activities: a rough timeframe ("December 2026") until the exact start is known. */
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dateTime('starts_at')->nullable()->change();
            $table->string('timeframe')->nullable()->after('starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('timeframe');
            $table->dateTime('starts_at')->nullable(false)->change();
        });
    }
};
