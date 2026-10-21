<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipt_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_sequence')->default(0);
            $table->timestamps();
        });

        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->smallInteger('year');
            $table->integer('sequence');
            $table->unique(['year', 'sequence']);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_proof_id')->nullable()->unique()->constrained('payment_proofs')->nullOnDelete();
            $table->string('member_name');
            $table->string('member_email')->nullable();
            $table->decimal('amount_received', 10, 2);
            $table->decimal('credit_used', 10, 2)->default(0);
            $table->decimal('credit_added', 10, 2)->default(0);
            $table->json('lines');
            $table->timestamp('verified_at');
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
        Schema::dropIfExists('receipt_counters');
    }
};
