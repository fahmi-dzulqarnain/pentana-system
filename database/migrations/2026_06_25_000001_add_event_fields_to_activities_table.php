<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('status')->default('draft');
            $table->unsignedInteger('capacity')->nullable();
            $table->dateTime('registration_closes_at')->nullable();
            $table->dateTime('ends_at')->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn([
                'status',
                'capacity',
                'registration_closes_at',
                'ends_at',
                'reminder_sent_at',
            ]);
        });
    }
};
