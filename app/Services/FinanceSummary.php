<?php

namespace App\Services;

use App\Models\Payment\PaymentProof;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The treasurer's figures: one row per enabled member plus the club-wide totals.
 * Every number here must equal FinanceService::outstanding() and User::$credit,
 * so the per-member expressions mirror them in portable SQL (SQLite + Postgres).
 * Aggregates come back as strings on Postgres - callers cast them.
 */
class FinanceSummary
{
    /**
     * What is still owed on one bill (fee_periods row): amount due minus what has been allocated to it.
     */
    private const BILL_REMAINDER = 'fee_periods.amount_due - COALESCE((SELECT SUM(payment_allocations.amount_allocated)'
        .' FROM payment_allocations WHERE payment_allocations.fee_period_id = fee_periods.id), 0)';

    /**
     * What is left unspent on one verified payment proof.
     */
    private const PROOF_REMAINDER = 'COALESCE(payment_proofs.amount_verified, 0) - COALESCE((SELECT SUM(payment_allocations.amount_allocated)'
        .' FROM payment_allocations WHERE payment_allocations.payment_proof_id = payment_proofs.id), 0)';

    /**
     * Enabled members (including those owing nothing) with the aliases
     * owes, unpaid_count, oldest_unpaid, oldest_unpaid_status, credit and
     * paid_this_month, cast to float/int/date/string/bool on the model.
     */
    public static function members(): Builder
    {
        $remainder = self::BILL_REMAINDER;
        $unpaidBills = fn () => self::bills()->whereRaw("{$remainder} > 0");

        return User::query()
            ->enabled()
            ->select('users.*')
            ->selectSub(self::owes(), 'owes')
            ->selectSub($unpaidBills()->selectRaw('COUNT(*)'), 'unpaid_count')
            ->selectSub($unpaidBills()->selectRaw('MIN(fee_periods.month_date)'), 'oldest_unpaid')
            ->selectSub(
                $unpaidBills()->orderBy('fee_periods.month_date')->limit(1)->select('fee_periods.status'),
                'oldest_unpaid_status'
            )
            ->selectSub(
                DB::table('payment_proofs')
                    ->whereColumn('payment_proofs.user_id', 'users.id')
                    ->where('payment_proofs.status', 'verified')
                    ->selectRaw('COALESCE(SUM('.self::PROOF_REMAINDER.'), 0)'),
                'credit'
            )
            ->selectSub(
                self::bills()
                    ->where('fee_periods.status', 'paid')
                    ->whereBetween('fee_periods.month_date', [
                        now()->startOfMonth()->toDateString(),
                        now()->endOfMonth()->toDateString(),
                    ])
                    ->selectRaw('CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END'),
                'paid_this_month'
            )
            ->withCasts([
                'owes' => 'float',
                'unpaid_count' => 'integer',
                'oldest_unpaid' => 'date',
                'credit' => 'float',
                'paid_this_month' => 'boolean',
            ])
            ->with('memberCategory');
    }

    /**
     * Narrow members() to those owing something. Postgres cannot filter on a
     * select alias, so the owes expression is repeated in the WHERE.
     */
    public static function owingOnly(Builder $query): Builder
    {
        return $query->where(self::owes(), '>', 0);
    }

    /** The member's bills, correlated to the outer users row. */
    private static function bills(): QueryBuilder
    {
        return DB::table('fee_periods')->whereColumn('fee_periods.user_id', 'users.id');
    }

    /** What the member owes: each bill's remainder, clamped at zero, summed. */
    private static function owes(): QueryBuilder
    {
        $remainder = self::BILL_REMAINDER;

        return self::bills()->selectRaw("COALESCE(SUM(CASE WHEN {$remainder} > 0 THEN {$remainder} ELSE 0 END), 0)");
    }

    /**
     * @return array{outstanding: float, owing_members: int, collected_this_month: float, collected_all_time: float, credit: float, credit_members: int, pending_count: int, oldest_pending_at: ?Carbon}
     */
    public static function totals(): array
    {
        $members = DB::query()
            ->fromSub(self::members(), 'm')
            ->selectRaw('COALESCE(SUM(m.owes), 0) AS outstanding')
            ->selectRaw('COALESCE(SUM(CASE WHEN m.owes > 0 THEN 1 ELSE 0 END), 0) AS owing_members')
            ->selectRaw('COALESCE(SUM(m.credit), 0) AS credit')
            ->selectRaw('COALESCE(SUM(CASE WHEN m.credit > 0 THEN 1 ELSE 0 END), 0) AS credit_members')
            ->first();

        $verified = fn () => PaymentProof::query()->where('status', 'verified');
        $pending = PaymentProof::query()->where('status', 'pending');
        $oldestPending = (clone $pending)->min('created_at');

        return [
            'outstanding' => round((float) $members->outstanding, 2),
            'owing_members' => (int) $members->owing_members,
            'collected_this_month' => round((float) $verified()
                ->whereBetween('verified_at', [now()->startOfMonth(), now()->endOfMonth()])
                ->sum('amount_verified'), 2),
            'collected_all_time' => round((float) $verified()->sum('amount_verified'), 2),
            'credit' => round((float) $members->credit, 2),
            'credit_members' => (int) $members->credit_members,
            'pending_count' => $pending->count(),
            'oldest_pending_at' => $oldestPending ? Carbon::parse($oldestPending) : null,
        ];
    }
}
