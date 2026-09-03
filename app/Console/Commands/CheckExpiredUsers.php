<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\NodeSyncService;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Log;

class CheckExpiredUsers extends Command
{
    protected $signature = 'check:expired-users';
    protected $description = '检查自然到期用户并通知节点移除';

    // 无状态回看窗口：每分钟重推最近 5 分钟内到期的用户，
    // 单次推送失败会在后续几轮自然补发（remove 幂等）。
    private const LOOKBACK = 300;

    public function handle()
    {
        $now = time();

        // 到期不会改变任何数据库字段，UserObserver 不会触发同步，需要主动扫描。
        $userIds = User::toBase()
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now - self::LOOKBACK)
            ->where('expired_at', '<=', $now)
            ->whereNotNull('group_id')
            ->pluck('id')
            ->all();

        if (empty($userIds)) {
            return;
        }

        // 与续费路径共用 notifyUserChanged：按用户加锁后重新读取状态再发布，
        // 保证同一用户的资格判断与消息顺序一致；已续费的用户只会发出幂等的 add。
        $synced = 0;
        foreach ($userIds as $userId) {
            $user = User::find($userId);
            if (!$user) {
                continue;
            }

            try {
                if (NodeSyncService::notifyUserChanged($user)) {
                    $synced++;
                }
            } catch (LockTimeoutException $e) {
                Log::warning("[CheckExpiredUsers] Lock timeout for user #{$userId}");
            }
        }

        $this->info("Processed " . count($userIds) . " recently expired users, synced {$synced}.");
    }
}
