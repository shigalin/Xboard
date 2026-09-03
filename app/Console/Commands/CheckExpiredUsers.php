<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckExpiredUsers extends Command
{
    protected $signature = 'check:expired-users';
    protected $description = '检查自然到期用户并通知节点移除';

    private const LAST_CHECKED_AT_KEY = 'check_expired_users:last_checked_at';

    // 首次运行（或缓存丢失）时回溯的时间窗口，避免漏掉刚过期的用户
    private const DEFAULT_LOOKBACK = 3600;

    public function handle()
    {
        $now = time();
        $since = (int) Cache::get(self::LAST_CHECKED_AT_KEY, $now - self::DEFAULT_LOOKBACK);

        // 到期不会改变任何数据库字段，UserObserver 不会触发同步，
        // 因此需要主动扫描 (since, now] 区间内到期的用户并推送 remove。
        $expiredUsers = User::toBase()
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $since)
            ->where('expired_at', '<=', $now)
            ->whereNotNull('group_id')
            ->select(['id', 'group_id'])
            ->get();

        Cache::forever(self::LAST_CHECKED_AT_KEY, $now);

        if ($expiredUsers->isEmpty()) {
            return;
        }

        $notifiedCount = 0;

        foreach ($expiredUsers->groupBy('group_id') as $groupId => $users) {
            $userIdsInGroup = $users->pluck('id')->toArray();
            $servers = Server::whereJsonContains('group_ids', (string) $groupId)->get();

            foreach ($servers as $server) {
                if (!NodeSyncService::isNodeOnline($server->id)) {
                    continue;
                }

                NodeSyncService::push($server->id, 'sync.user.delta', [
                    'action' => 'remove',
                    'users' => array_map(fn($id) => ['id' => $id], $userIdsInGroup),
                ]);
                $notifiedCount++;
            }
        }

        $this->info("Found {$expiredUsers->count()} expired users since {$since}, notified {$notifiedCount} nodes.");
    }
}
