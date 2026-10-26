<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Plain strings, not DB enums: the same schema must run on SQLite (tests) and Postgres (prod).
        Schema::create('bank_statement_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('uploaded_by')->nullable()->index()->constrained('users')->nullOnDelete();
            $table->string('file_sha256', 64)->unique();
            $table->date('statement_from');
            $table->date('statement_to');
            $table->unsignedInteger('lines_new')->default(0);
            $table->unsignedInteger('lines_duplicate')->default(0);
            // No stored outcome counts: they are counted live from the import's own lines.
            $table->timestamps();
        });

        Schema::create('bank_statement_lines', function (Blueprint $table) {
            $table->id();
            // Explicit indexes: Postgres doesn't index foreign keys on its own.
            $table->foreignId('bank_statement_import_id')->index()->constrained()->cascadeOnDelete();
            $table->date('txn_date');
            $table->string('type', 50);
            $table->string('bank_ref_no', 100);
            $table->decimal('amount', 10, 2);
            // 1-based position among this statement's credits with the same date, ref and amount:
            // two real identical transfers on one day stay two lines.
            $table->unsignedSmallInteger('occurrence')->default(1);
            $table->text('reference_text');
            $table->foreignId('user_id')->nullable()->index()->constrained()->nullOnDelete();
            // Unique: one proof is evidence for at most one bank credit.
            $table->foreignId('payment_proof_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('outcome', 20);
            $table->timestamps();

            // Re-importing an overlapping statement must not double-count a credit.
            $table->unique(['txn_date', 'bank_ref_no', 'amount', 'occurrence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_statement_lines');
        Schema::dropIfExists('bank_statement_imports');
    }
};
