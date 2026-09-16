<?php

namespace Woda\Ralph\Commands;

use Illuminate\Console\Command;
use Woda\Ralph\SessionTracker;

class WatchCommand extends Command
{
    protected $signature = 'ralph:watch';

    protected $description = 'Run one recovery tick for registered sessions; schedule every minute on the host';

    public function handle(SessionTracker $tracker): int
    {
        $tracker->recover();

        return self::SUCCESS;
    }
}
