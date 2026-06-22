<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fee_periods', function (Blueprint $table) {
            $table->string('type')->default('regular'); // 'regular', 'free', 'adjustment'
            $table->string('remarks')->nullable(); // e.g., "Ramadan Special - Free Month"
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fee_periods', function (Blueprint $table) {
            $table->dropColumn(['type', 'remarks']);
        });
    }
};
