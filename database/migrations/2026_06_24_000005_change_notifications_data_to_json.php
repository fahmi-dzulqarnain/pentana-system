<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Filament's database-notifications query filters with the Postgres JSON operator
     * `data->>'format' = 'filament'`, which requires a json/jsonb column. Laravel's default
     * notifications migration creates `data` as text, so the bell query fails on Postgres
     * (SQLite/MySQL apply JSON operators to text fine, which is why tests don't catch it).
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE json USING data::json');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE notifications ALTER COLUMN data TYPE text USING data::text');
        }
    }
};
