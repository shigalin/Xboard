<?php

namespace App\Console\Commands;

use App\Models\AdminAuditLog;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\UserServerTraffic;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;

class ResetLog extends Command
{
    protected $builder;
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'reset:log';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '清空日志';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        StatUser::where('record_at', '<', strtotime('-2 month', time()))->delete();
        StatServer::where('record_at', '<', strtotime('-2 month', time()))->delete();
        $today = Date::today()->timestamp;
        UserServerTraffic::where('in_cycle', false)
            ->where(fn ($query) => $query->whereNull('daily_record_at')->orWhere('daily_record_at', '<', $today))
            ->delete();
        AdminAuditLog::where('created_at', '<', strtotime('-3 month', time()))->delete();
    }
}
