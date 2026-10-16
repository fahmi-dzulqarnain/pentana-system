<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. nullable columns
        Schema::table('lunch_options', function (Blueprint $table) {
            $table->foreignId('lunch_menu_id')->nullable()->constrained('lunch_menus')->cascadeOnDelete();
        });
        Schema::table('lunch_responses', function (Blueprint $table) {
            $table->foreignId('lunch_option_id')->nullable()->constrained('lunch_options')->cascadeOnDelete();
        });

        // 2. one menu per existing lunch
        $now = now();
        foreach (DB::table('lunches')->get(['id', 'caterer_id', 'caterer_meal_id']) as $lunch) {
            DB::table('lunch_menus')->insert([
                'lunch_id' => $lunch->id,
                'caterer_id' => $lunch->caterer_id,
                'caterer_meal_id' => $lunch->caterer_meal_id,
                'sort' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. backfill
        foreach (DB::table('lunch_menus')->get(['id', 'lunch_id']) as $menu) {
            DB::table('lunch_options')->where('lunch_id', $menu->lunch_id)->update(['lunch_menu_id' => $menu->id]);
        }
        foreach (DB::table('lunch_options')->get(['id', 'lunch_id', 'meal_option_id']) as $option) {
            DB::table('lunch_responses')
                ->where('lunch_id', $option->lunch_id)
                ->where('meal_option_id', $option->meal_option_id)
                ->update(['lunch_option_id' => $option->id]);
        }

        // 4 + 5. NOT NULL and swap unique index
        Schema::table('lunch_options', function (Blueprint $table) {
            $table->dropUnique(['lunch_id', 'meal_option_id']);
        });
        $this->keepingChildRows(function () {
            Schema::table('lunch_options', function (Blueprint $table) {
                $table->foreignId('lunch_menu_id')->nullable(false)->change();
                $table->unique(['lunch_menu_id', 'meal_option_id']);
            });
        });

        // 6. drop the old columns
        $this->keepingChildRows(function () {
            Schema::table('lunches', function (Blueprint $table) {
                $table->dropConstrainedForeignId('caterer_id');
                $table->dropConstrainedForeignId('caterer_meal_id');
            });
        });
    }

    public function down(): void
    {
        $this->keepingChildRows(function () {
            Schema::table('lunches', function (Blueprint $table) {
                $table->foreignId('caterer_id')->nullable()->constrained()->cascadeOnDelete();
                $table->foreignId('caterer_meal_id')->nullable()->constrained()->cascadeOnDelete();
            });
        });

        foreach (DB::table('lunches')->pluck('id') as $lunchId) {
            $menu = DB::table('lunch_menus')->where('lunch_id', $lunchId)->orderBy('sort')->orderBy('id')->first();
            if ($menu) {
                DB::table('lunches')->where('id', $lunchId)->update([
                    'caterer_id' => $menu->caterer_id,
                    'caterer_meal_id' => $menu->caterer_meal_id,
                ]);
            }
        }

        // Keep only each lunch's first menu's options, so (lunch_id, meal_option_id) is unique again.
        foreach (DB::table('lunch_menus')->orderBy('sort')->orderBy('id')->get(['id', 'lunch_id']) as $menu) {
            $first = DB::table('lunch_menus')->where('lunch_id', $menu->lunch_id)->orderBy('sort')->orderBy('id')->value('id');
            if ($first !== $menu->id) {
                $optionIds = DB::table('lunch_options')->where('lunch_menu_id', $menu->id)->pluck('id');
                // Their picks become "no matching option" rather than being deleted with the option.
                DB::table('lunch_responses')->whereIn('lunch_option_id', $optionIds)->update(['lunch_option_id' => null]);
                DB::table('lunch_options')->where('lunch_menu_id', $menu->id)->delete();
            }
        }

        $this->keepingChildRows(function () {
            Schema::table('lunches', function (Blueprint $table) {
                $table->foreignId('caterer_id')->nullable(false)->change();
                $table->foreignId('caterer_meal_id')->nullable(false)->change();
            });
        });

        Schema::table('lunch_options', function (Blueprint $table) {
            $table->dropUnique(['lunch_menu_id', 'meal_option_id']);
        });
        $this->keepingChildRows(function () {
            Schema::table('lunch_options', function (Blueprint $table) {
                $table->dropConstrainedForeignId('lunch_menu_id');
                $table->unique(['lunch_id', 'meal_option_id']);
            });
        });
        $this->keepingChildRows(function () {
            Schema::table('lunch_responses', function (Blueprint $table) {
                $table->dropConstrainedForeignId('lunch_option_id');
            });
        });
    }

    /**
     * SQLite rebuilds a table to change or drop a foreign-keyed column, and dropping the old copy
     * cascade-deletes rows that reference it. Postgres alters in place, so only SQLite needs this:
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
