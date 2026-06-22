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
        Schema::create('member_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // e.g., "Grade 7-10", "Grade 11-12", "Grade 13-15"
            $table->decimal('default_fee', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('member_category_id')->nullable()->constrained();
            // Allow an optional manual override per user if they have a special deal
            $table->decimal('custom_fee', 10, 2)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('member_categories');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['member_category_id', 'custom_fee']);
        });
    }
};
