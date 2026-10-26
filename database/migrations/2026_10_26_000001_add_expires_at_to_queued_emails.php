<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queued_emails', function (Blueprint $table) {
            // A "last call to vote" drained after voting closed is worse than no email at all.
            $table->timestamp('expires_at')->nullable()->after('next_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('queued_emails', function (Blueprint $table) {
            $table->dropColumn('expires_at');
        });
    }
};
