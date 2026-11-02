<?php

namespace App\Console\Commands;

use App\Services\LunchPublisher;
use Illuminate\Console\Command;

class AnnounceLunches extends Command
{
    protected $signature = 'app:announce-lunches';

    protected $description = 'Tell members about newly published lunch days, bundled into one announcement';

    public function handle(LunchPublisher $publisher): int
    {
        $this->info("Announced {$publisher->announcePending()} lunch day(s).");

        return self::SUCCESS;
    }
}
