<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_meal_option', function (Blueprint $table) {
            $table->foreignId('meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_option_id')->constrained()->cascadeOnDelete();
            $table->primary(['meal_id', 'meal_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_meal_option');
    }
};
