<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auto_emails', function (Blueprint $table) {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->dateTime('send_at')->nullable();
            $table->string('status')->default('draft'); // draft, scheduled, sent
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auto_emails');
    }
};
