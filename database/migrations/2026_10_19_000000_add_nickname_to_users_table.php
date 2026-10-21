<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('nickname', 30)->nullable()->after('name');
        });

        // Case-insensitive uniqueness; works on SQLite and Postgres, and NULLs never collide.
        DB::statement('CREATE UNIQUE INDEX users_nickname_lower_unique ON users (lower(nickname))');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX users_nickname_lower_unique');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('nickname');
        });
    }
};
