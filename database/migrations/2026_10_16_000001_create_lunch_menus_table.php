<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunch_menus', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lunch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caterer_id')->constrained();
            $table->foreignId('caterer_meal_id')->constrained();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();

            $table->unique(['lunch_id', 'caterer_meal_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunch_menus');
    }
};
