<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NodeSyncService;
use App\WebSocket\NodeWorker;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

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

        // 先固定起点再扫描：否则首轮推送失败时，下一轮会按 now-LOOKBACK
        // 重新计算，窗口前移，边缘的失败用户会被漏掉。
        $since = Cache::get(self::LAST_CHECKED_AT_KEY);
        if ($since === null) {
            $since = $now - self::DEFAULT_LOOKBACK;
            Cache::forever(self::LAST_CHECKED_AT_KEY, $since);
        }
        $since = (int) $since;

        // 到期不会改变任何数据库字段，UserObserver 不会触发同步，
        // 因此需要主动扫描 (since, now] 区间内到期的用户。
        $candidateIds = User::toBase()
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $since)
            ->where('expired_at', '<=', $now)
            ->whereNotNull('group_id')
            ->pluck('id')
            ->all();

        if (empty($candidateIds)) {
            Cache::forever(self::LAST_CHECKED_AT_KEY, $now);
            return;
        }

        // WS 服务未运行时任何推送都不会有接收者；保留进度等其恢复后补发，
        // 节点重连时也会收到全量同步。
        if (!Cache::has(NodeWorker::HEARTBEAT_CACHE_KEY)) {
            $this->warn("WS server heartbeat missing, keeping checkpoint at {$since}.");
            return;
        }

        // 与续费路径共用 notifyUserChanged：在按用户加锁后重新读取状态再决定
        // add/remove 并发布，保证同一用户的资格判断与消息顺序一致。
        // 已续费的用户在锁内会被判定为可用，只会发出幂等的 add。
        $allPushed = true;
        $notified = 0;

        foreach ($candidateIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            try {
                if (NodeSyncService::notifyUserChanged($user)) {
                    $notified++;
                } else {
                    $allPushed = false;
                }
            } catch (LockTimeoutException $e) {
                Log::warning("[CheckExpiredUsers] Lock timeout for user #{$userId}, will retry next run");
                $allPushed = false;
            }
        }

        // 只有全部成功才推进进度；否则保留 since，
        // 下一轮会重扫同一区间补发（remove 幂等，重复推送无副作用）。
        if ($allPushed) {
            Cache::forever(self::LAST_CHECKED_AT_KEY, $now);
        } else {
            $this->warn("Some pushes failed, keeping checkpoint at {$since} for retry.");
        }

        $this->info("Processed " . count($candidateIds) . " expired users since {$since}, synced {$notified}.");
    }
}
