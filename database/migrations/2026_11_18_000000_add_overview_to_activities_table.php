<?php

use App\Models\Activity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Splits an activity's description in two: a short plain-text `overview` for lists and cards,
 * while `description` keeps the full rich HTML (now with images). Existing activities get an
 * overview generated from their description so cards never go blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('activities', 'overview')) {
            Schema::table('activities', function (Blueprint $table) {
                $table->string('overview', 255)->nullable()->after('title');
            });
        }

        DB::table('activities')
            ->whereNull('overview')
            ->whereNotNull('description')
            ->orderBy('id')
            ->chunkById(100, function ($activities) {
                foreach ($activities as $activity) {
                    DB::table('activities')
                        ->where('id', $activity->id)
                        ->update(['overview' => Activity::plainTextExcerpt($activity->description)]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('overview');
        });
    }
};
