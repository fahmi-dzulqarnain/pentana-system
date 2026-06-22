<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunches', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->foreignId('caterer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('caterer_meal_id')->constrained()->cascadeOnDelete();
            $table->dateTime('deadline');
            $table->string('status')->default('draft'); // draft, published, closed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunches');
    }
};
