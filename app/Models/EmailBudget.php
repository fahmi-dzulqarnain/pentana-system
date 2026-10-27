<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * One row per UTC date, counting email sent against Resend's free-tier limits.
 *
 * Both ceilings are enforced in claim() because a monthly overrun cannot be solved by
 * waiting until tomorrow - the system has to stop sending and show red.
 */
class EmailBudget extends Model
{
    public const DAILY_LIMIT = 100;

    public const MONTHLY_LIMIT = 3000;

    protected $table = 'email_budget';

    protected $primaryKey = 'date';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['date', 'sent_count'];

    protected function casts(): array
    {
        return ['sent_count' => 'integer'];
    }

    public static function today(): string
    {
        return Carbon::now('UTC')->toDateString();
    }

    /**
     * Grant one send, or refuse. One call == one message, regardless of how many
     * recipients that message carries.
     *
     * lockForUpdate() rather than Postgres's `UPDATE ... RETURNING` so the contended path
     * is the same code under SQLite tests and Postgres production. The lock is a no-op on
     * SQLite, so concurrency is not covered by this suite - see the spec's known limitation.
     */
    public static function claim(): bool
    {
        return DB::transaction(function (): bool {
            $date = static::today();

            static::firstOrCreate(['date' => $date], ['sent_count' => 0]);

            $row = static::where('date', $date)->lockForUpdate()->first();

            if ($row->sent_count >= self::DAILY_LIMIT) {
                return false;
            }

            if (static::monthToDate() >= self::MONTHLY_LIMIT) {
                return false;
            }

            $row->increment('sent_count');

            return true;
        });
    }

    /** Give a granted slot back - the provider refused it, so it must not count. */
    public static function release(): void
    {
        DB::transaction(function (): void {
            $row = static::where('date', static::today())->lockForUpdate()->first();

            if ($row && $row->sent_count > 0) {
                $row->decrement('sent_count');
            }
        });
    }

    public static function usedToday(): int
    {
        return (int) static::where('date', static::today())->value('sent_count');
    }

    public static function monthToDate(): int
    {
        $now = Carbon::now('UTC');

        return (int) static::whereBetween('date', [
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString(),
        ])->sum('sent_count');
    }
}
