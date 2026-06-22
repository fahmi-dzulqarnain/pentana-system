<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lunch_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lunch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_option_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['lunch_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lunch_responses');
    }
};
