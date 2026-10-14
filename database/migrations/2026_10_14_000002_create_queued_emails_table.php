<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queued_emails', function (Blueprint $table) {
            $table->id();
            $table->longText('mime');
            $table->json('recipients');
            $table->string('sender');
            $table->string('subject')->nullable();
            $table->unsignedSmallInteger('priority')->default(50);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['sent_at', 'failed_at', 'priority', 'created_at'], 'queued_emails_drain_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queued_emails');
    }
};
