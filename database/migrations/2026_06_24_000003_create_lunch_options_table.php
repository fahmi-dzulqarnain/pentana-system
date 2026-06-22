<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunch_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lunch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_option_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('default_count')->default(0);
            $table->timestamps();
            $table->unique(['lunch_id', 'meal_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunch_options');
    }
};
