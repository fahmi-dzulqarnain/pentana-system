<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit is no longer a stored scalar. A verified payment proof's unallocated
     * remainder (amount_verified - sum of its allocations) IS the member's credit,
     * so every allocation always links to a real proof. See FinanceService.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('credit_balance');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('credit_balance', 10, 2)->default(0)->after('email');
        });
    }
};
