<?php

namespace App\Support;

/**
 * Display labels for the status/type values stored in the database. The stored value
 * ('published') never changes; only what admins see in badges, filters and selects is
 * translated.
 */
class StatusLabel
{
    public static function for(?string $state): string
    {
        return match ($state) {
            'draft' => __('Draft'),
            'published' => __('Published'),
            'cancelled' => __('Cancelled'),
            'closed' => __('Closed'),
            'scheduled' => __('Scheduled'),
            'sent' => __('Sent'),
            'pending' => __('Pending'),
            'verified' => __('Verified'),
            'rejected' => __('Rejected'),
            'registered' => __('Registered'),
            'waitlisted' => __('Waitlisted'),
            'paid' => __('Paid'),
            'partial' => __('Partial'),
            'unpaid' => __('Unpaid'),
            'one_off' => __('One-off'),
            'birthday' => __('Birthday email'),
            'regular' => __('Regular Fee'),
            'free' => __('Free Month'),
            'adjustment' => __('Manual Adjustment'),
            null => '',
            default => ucwords(str_replace('_', ' ', $state)),
        };
    }

    /**
     * Select/filter options for the given stored values.
     *
     * @param  list<string>  $states
     * @return array<string, string>
     */
    public static function options(array $states): array
    {
        return collect($states)->mapWithKeys(fn (string $state): array => [$state => self::for($state)])->all();
    }
}
