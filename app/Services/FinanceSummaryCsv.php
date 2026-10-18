<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams the finance summary rows as a CSV download, synchronously (no queue worker in production).
 * $members is FinanceSummary::members(), already filtered/searched/sorted by the page.
 */
class FinanceSummaryCsv
{
    public static function stream(Builder $members, CarbonInterface $date): StreamedResponse
    {
        return response()->streamDownload(function () use ($members): void {
            $out = fopen('php://output', 'w');

            // BOM so Excel reads the file as UTF-8.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                __('Name'),
                __('Email'),
                __('Category'),
                __('Owes (MYR)'),
                __('Unpaid months'),
                __('Oldest unpaid'),
                __('Credit (MYR)'),
                __('Paid this month'),
            ], ',', '"', '');

            foreach ($members->get() as $member) {
                fputcsv($out, [
                    $member->name,
                    $member->email,
                    $member->memberCategory?->name,
                    number_format((float) $member->owes, 2, '.', ''),
                    (int) $member->unpaid_count,
                    $member->oldest_unpaid?->format('Y-m'),
                    number_format((float) $member->credit, 2, '.', ''),
                    $member->paid_this_month ? __('Yes') : __('No'),
                ], ',', '"', '');
            }

            fclose($out);
        }, 'finance-summary-'.$date->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
