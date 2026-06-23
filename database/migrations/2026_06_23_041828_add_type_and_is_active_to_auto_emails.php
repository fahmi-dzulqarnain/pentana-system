<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auto_emails', function (Blueprint $table) {
            $table->string('type')->default('one_off')->after('id'); // one_off | birthday
            $table->boolean('is_active')->default(true)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('auto_emails', function (Blueprint $table) {
            $table->dropColumn(['type', 'is_active']);
        });
    }
};
