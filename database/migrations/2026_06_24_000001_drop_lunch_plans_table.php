<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('lunch_plans');
    }

    public function down(): void
    {
        Schema::create('lunch_plans', function (Blueprint $table) {
            $table->id();
            $table->date('date')->index();
            $table->string('menu');
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }
};
