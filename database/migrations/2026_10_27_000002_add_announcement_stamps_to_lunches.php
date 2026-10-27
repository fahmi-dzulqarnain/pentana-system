<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lunches', function (Blueprint $table) {
            // Publishing no longer emails on the spot: app:announce-lunches bundles every day
            // published within a quiet window into one announcement, then stamps announced_at.
            $table->timestamp('published_at')->nullable()->after('status');
            $table->timestamp('announced_at')->nullable()->after('published_at');
        });

        // Days already out were announced when they were published; don't announce them again.
        DB::table('lunches')->where('status', '!=', 'draft')
            ->update(['published_at' => DB::raw('updated_at'), 'announced_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('lunches', function (Blueprint $table) {
            $table->dropColumn(['published_at', 'announced_at']);
        });
    }
};
