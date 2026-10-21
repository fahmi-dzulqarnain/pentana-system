<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->keepingChildRows(function () {
            Schema::table('lunches', function (Blueprint $table) {
                $table->timestamp('manual_reminder_sent_at')->nullable();
                $table->foreignId('manual_reminder_sent_by')->nullable()->constrained('users')->nullOnDelete();
            });
        });
    }

    public function down(): void
    {
        $this->keepingChildRows(function () {
            Schema::table('lunches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('manual_reminder_sent_by');
                $table->dropColumn('manual_reminder_sent_at');
            });
        });
    }

    /**
     * SQLite rebuilds `lunches` to add or drop a foreign-keyed column, and dropping the old copy
     * cascade-deletes the rows that reference it. Postgres alters in place, so only SQLite needs this:
     * snapshot the dependent tables and put back whatever the rebuild took with it.
     */
    private function keepingChildRows(callable $alter): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $alter();

            return;
        }

        $tables = ['lunch_menus', 'lunch_options', 'lunch_responses'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }

        $alter();

        foreach ($tables as $table) {
            if (DB::table($table)->count() === count($snapshot[$table])) {
                continue;
            }
            $columns = array_flip(Schema::getColumnListing($table));
            DB::table($table)->delete();
            foreach ($snapshot[$table] as $row) {
                DB::table($table)->insert(array_intersect_key($row, $columns));
            }
        }
    }
};
