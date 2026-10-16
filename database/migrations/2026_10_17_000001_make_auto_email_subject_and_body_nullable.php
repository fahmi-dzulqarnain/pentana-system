<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // A birthday template with no subject/body sends the built-in, translated message.
    public function up(): void
    {
        Schema::table('auto_emails', function (Blueprint $table) {
            $table->string('subject')->nullable()->change();
            $table->text('body')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('auto_emails', function (Blueprint $table) {
            $table->string('subject')->nullable(false)->change();
            $table->text('body')->nullable(false)->change();
        });
    }
};
