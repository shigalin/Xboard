<?php

namespace App\Console\Commands;

use App\Services\DeviceStateService;
use Illuminate\Console\Command;

class SyncDeviceState extends Command
{
    protected $signature = 'sync:device-state
                            {--limit=20000 : Maximum users to process per run}
                            {--seconds=50 : Maximum wall-clock seconds per run}';

    protected $description = 'Flush pending Redis device counts to the user table';

    public function handle(DeviceStateService $service): int
    {
        $limit = max(1, min(100000, (int) $this->option('limit')));
        $seconds = max(1, min(55, (int) $this->option('seconds')));
        $deadline = microtime(true) + $seconds;
        $processed = 0;

        do {
            $batchSize = min(200, $limit - $processed);
            $batchProcessed = $service->flushPendingUpdates($batchSize);
            $processed += $batchProcessed;
        } while (
            $processed < $limit
            && $batchProcessed === $batchSize
            && microtime(true) < $deadline
        );

        return self::SUCCESS;
    }
}
