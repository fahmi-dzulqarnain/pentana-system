<?php

namespace App\Console\Commands;

use App\Models\Lunch;
use Illuminate\Console\Command;

class CloseLunchOrders extends Command
{
    protected $signature = 'app:close-lunch-orders';

    protected $description = 'Close published lunches whose ordering deadline has passed';

    public function handle(): int
    {
        // Strictly past the deadline: the exact boundary second stays open, matching
        // Lunch::isOpenForVoting() which treats `now <= deadline` as still open.
        $closed = Lunch::where('status', 'published')
            ->where('deadline', '<', now())
            ->update(['status' => 'closed']);

        $this->info("Closed {$closed} lunch(es).");

        return self::SUCCESS;
    }
}
