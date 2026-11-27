<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

final class BestEffortLog
{
    public static function write(string $level, string $message, array $context = []): void
    {
        try {
            Log::log($level, $message, $context);
        } catch (\Throwable) {
            // Keep node synchronization running when the log channel fails.
        }
    }
}
