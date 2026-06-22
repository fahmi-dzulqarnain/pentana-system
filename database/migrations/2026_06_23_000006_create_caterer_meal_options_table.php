<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('caterer_meal_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('caterer_meal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_option_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable(); // override; null = use caterer_meal.price
            $table->timestamps();
            $table->unique(['caterer_meal_id', 'meal_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('caterer_meal_options');
    }
};
