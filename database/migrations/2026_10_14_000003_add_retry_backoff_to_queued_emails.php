<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('queued_emails', function (Blueprint $table) {
            // Without a backoff the drain retries every minute, so three minutes of
            // provider trouble exhausts all attempts and destroys the backlog.
            $table->timestamp('next_attempt_at')->nullable()->after('attempts');
        });

        // "New activity: {title}" reaches 269 chars for a 255-char title; varchar(255)
        // rejects it on Postgres and the mail is lost before it can be spooled.
        Schema::table('queued_emails', function (Blueprint $table) {
            $table->text('subject')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('queued_emails', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
            $table->string('subject')->nullable()->change();
        });
    }
};
