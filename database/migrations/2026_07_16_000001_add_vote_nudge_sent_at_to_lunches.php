<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lunches', function (Blueprint $table) {
            $table->timestamp('vote_nudge_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('lunches', function (Blueprint $table) {
            $table->dropColumn('vote_nudge_sent_at');
        });
    }
};
