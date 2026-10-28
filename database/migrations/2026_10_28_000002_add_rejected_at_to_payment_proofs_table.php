<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            // The mobile Home shows a "Proof rejected" to-do for a while after rejection.
            $table->timestamp('rejected_at')->nullable()->after('verified_at');
        });

        // Proofs rejected before this column existed: updated_at is the best available stamp.
        DB::table('payment_proofs')->where('status', 'rejected')->whereNull('rejected_at')
            ->update(['rejected_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('payment_proofs', function (Blueprint $table) {
            $table->dropColumn('rejected_at');
        });
    }
};
