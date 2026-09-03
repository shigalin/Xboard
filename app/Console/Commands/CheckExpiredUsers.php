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
        $candidates = User::toBase()
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $since)
            ->where('expired_at', '<=', $now)
            ->whereNotNull('group_id')
            ->select(['id', 'group_id'])
            ->get();

        if ($candidates->isEmpty()) {
            Cache::forever(self::LAST_CHECKED_AT_KEY, $now);
            return;
        }

        // 先把节点查完，再在推送前用最新状态复核名单：
        // 若用户在扫描后完成续费，expired_at 已是未来时间，会在此被过滤掉，
        // 避免旧快照覆盖续费产生的 add 通知。
        $onlineServersByGroup = $this->resolveOnlineServersByGroup(
            $candidates->pluck('group_id')->unique()->all()
        );

        $expiredUsers = User::toBase()
            ->whereIn('id', $candidates->pluck('id')->all())
            ->where('expired_at', '<=', time())
            ->whereNotNull('group_id')
            ->select(['id', 'group_id'])
            ->get();

        $allPushed = true;
        $notifiedCount = 0;

        foreach ($expiredUsers->groupBy('group_id') as $groupId => $users) {
            $servers = $onlineServersByGroup[$groupId] ?? [];
            if (empty($servers)) {
                continue;
            }

            $payload = [
                'action' => 'remove',
                'users' => $users->map(fn($u) => ['id' => $u->id])->values()->all(),
            ];

            foreach ($servers as $serverId) {
                if (NodeSyncService::push($serverId, 'sync.user.delta', $payload)) {
                    $notifiedCount++;
                } else {
                    $allPushed = false;
                }
            }
        }

        // 只有全部推送成功才推进进度；否则保留 since，
        // 下一轮会重扫同一区间补发（remove 幂等，重复推送无副作用）。
        if ($allPushed) {
            Cache::forever(self::LAST_CHECKED_AT_KEY, $now);
        } else {
            $this->warn("Some pushes failed, keeping checkpoint at {$since} for retry.");
        }

        $this->info("Found {$expiredUsers->count()} expired users since {$since}, notified {$notifiedCount} nodes.");
    }

    /**
     * @return array<int, int[]> groupId => 在线节点 ID 列表
     */
    private function resolveOnlineServersByGroup(array $groupIds): array
    {
        $result = [];
        foreach ($groupIds as $groupId) {
            $serverIds = Server::whereJsonContains('group_ids', (string) $groupId)
                ->pluck('id')
                ->filter(fn($id) => NodeSyncService::isNodeOnline($id))
                ->values()
                ->all();

            if (!empty($serverIds)) {
                $result[$groupId] = $serverIds;
            }
        }

        return $result;
    }
}
