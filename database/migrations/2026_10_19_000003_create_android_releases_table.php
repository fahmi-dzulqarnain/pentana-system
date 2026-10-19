<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('android_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version_name');
            $table->unsignedInteger('version_code')->unique();
            $table->string('apk_path');
            $table->unsignedBigInteger('apk_size');
            $table->char('sha256', 64);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('android_releases');
    }
};
