<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Update Users Table (Adding the Wallet)
        Schema::table('users', function (Blueprint $table) {
            // Stores overpayment/surplus (e.g., user paid MYR 35 for a $30 fee)
            $table->decimal('credit_balance', 10, 2)->default(0)->after('email');
        });

        // The Fee Periods (The "Bills")
        // Will be populated monthly via a Scheduled Task (Cron)
        Schema::create('fee_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('month_date')->index();
            $table->decimal('amount_due', 10, 2);
            $table->string('status')->default('unpaid'); // unpaid, partial, paid
            $table->timestamps();

            // Prevent duplicate bills for the same user in the same month
            $table->unique(['user_id', 'month_date']);
        });

        Schema::create('payment_proofs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('proof_image_path');

            // The amount the User CLAIMS they sent (Admin verifies this later)
            $table->decimal('amount_claimed', 10, 2);

            // The actual amount received (Admin fills this)
            $table->decimal('amount_verified', 10, 2)->nullable();

            $table->string('status')->default('pending'); // pending, verified, rejected
            $table->text('admin_note')->nullable();
            $table->timestamps();
        });

        // This will connects one Payment Proof to multiple Fee Periods if any
        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_proof_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_period_id')->constrained()->cascadeOnDelete();

            // How much of this specific proof went to the specific month
            $table->decimal('amount_allocated', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payment_proofs');
        Schema::dropIfExists('fee_periods');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['credit_balance']);
        });
    }
};
