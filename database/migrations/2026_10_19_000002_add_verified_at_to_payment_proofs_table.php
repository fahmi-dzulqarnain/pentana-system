<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // When the payment was verified, for the finance summary's date filtering.
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->timestamp('verified_at')->nullable()->after('status');
        });

        // Best available approximation for proofs verified before this column existed.
        DB::table('payment_proofs')
            ->where('status', 'verified')
            ->whereNull('verified_at')
            ->update(['verified_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropColumn('verified_at');
        });
    }
};
