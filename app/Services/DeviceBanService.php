<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;

class DeviceBanService
{
    private const KEY = 'device:bans';

    private const START_BAN = <<<'LUA'
        local now = tonumber(ARGV[3])
        local deadline = now + 180000
        local proposed = tonumber(ARGV[2])
        if proposed > 0 then
            deadline = math.min(deadline, proposed)
        end
        if deadline <= now then
            return 0
        end
        local existing = tonumber(redis.call('ZSCORE', KEYS[1], ARGV[1]))
        if existing and (existing > now or (proposed > 0 and proposed - 180000 < existing)) then
            return 0
        end
        -- Retain expired deadlines until older online-IP reports have aged out.
        redis.call('ZREMRANGEBYSCORE', KEYS[1], '-inf', now - 300000)
        redis.call('ZADD', KEYS[1], deadline, ARGV[1])
        redis.call('PEXPIRE', KEYS[1], 480000)
        return deadline
        LUA;

    public function activeBans(Server $node): array
    {
        $now = (int) floor(microtime(true) * 1000);
        $active = Redis::zrangebyscore(self::KEY, '(' . $now, '+inf', ['withscores' => true]);
        $users = ServerService::getAvailableUsers($node, array_keys($active));

        return array_map('intval', array_intersect_key($active, $users->keyBy('id')->all()));
    }

    private function getAdmissionIPs(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }
        return app(DeviceStateService::class)->getUsersDevices($userIds, $this->getBanDeadlines());
    }

    public function getDeviceSnapshot(array $userIds): array
    {
        if ($userIds === []) {
            return ['users' => []];
        }

        try {
            $deadlines = $this->getBanDeadlines();
        } catch (\Throwable $error) {
            Log::warning('Could not load device bans for device snapshot', ['error' => $error::class]);
            $deadlines = [];
        }
        $now = (int) floor(microtime(true) * 1000);
        $deadlines = array_intersect_key($deadlines, array_fill_keys($userIds, true));
        $active = array_filter($deadlines, static fn ($until) => $until > $now);
        // Older nodes still need current IPs during a ban. Upgraded nodes
        // suppress those IPs locally when they apply the ban in this snapshot.
        $snapshot = ['users' => app(DeviceStateService::class)->getUsersDevices($userIds, array_diff_key($deadlines, $active))];
        if ($active !== []) {
            $snapshot['device_bans'] = (object) array_map('intval', $active);
        }

        return $snapshot;
    }

    private function getBanDeadlines(): array
    {
        return Redis::zrangebyscore(self::KEY, '-inf', '+inf', ['withscores' => true]);
    }

    public function reportBans(Server $node, array $reports): void
    {
        $reports = array_filter(array_map('intval', $reports), static fn (int $until) => $until > 0);
        $eligible = ServerService::getAvailableUsers($node, array_keys($reports))
            ->where('device_limit', '>', 0)->keyBy('id')->all();
        $this->publishNewBans(array_intersect_key($reports, $eligible));
    }

    public function checkReportedDevices(Server|int $node, array $alive, array $affectedUserIds): void
    {
        $reported = array_filter(
            array_intersect_key($alive, array_fill_keys($affectedUserIds, true)),
            static fn ($ips) => is_array($ips) && $ips !== []
        );
        if ($reported === []) {
            return;
        }

        $userIds = array_keys($reported);
        $nodeId = $node instanceof Server ? $node->id : $node;
        try {
            $node = is_int($node) ? Server::find($node) : $node;
            if ($node === null) {
                return;
            }
            $limits = ServerService::getAvailableUsers($node, $userIds)
                ->where('device_limit', '>', 0)->pluck('device_limit', 'id')->all();

            $overLimit = [];
            foreach ($this->getAdmissionIPs(array_keys($limits)) as $id => $ips) {
                if (DeviceStateService::countDevices($ips) > $limits[$id]) {
                    $overLimit[$id] = 0;
                }
            }
            $this->publishNewBans($overLimit);
        } catch (\Throwable $error) {
            // The IPs were saved. Let the next report retry even if the set is unchanged.
            try {
                app(DeviceStateService::class)->retryDeviceCheck($userIds);
            } catch (\Throwable $retryError) {
                Log::warning('Could not reschedule device limit check', [
                    'node_id' => $nodeId,
                    'error' => $retryError::class,
                ]);
            }
            Log::warning('Device limit check failed after saving the report', [
                'node_id' => $nodeId,
                'error' => $error::class,
            ]);
        }
    }

    private function publishNewBans(array $reports): void
    {
        $newBans = [];
        foreach ($reports as $id => $until) {
            // Use the panel clock for both ban deadlines and IP observations.
            $now = (int) floor(microtime(true) * 1000);
            $deadline = (int) Redis::eval(self::START_BAN, 1, self::KEY, $id, $until, $now);
            if ($deadline > 0) {
                $newBans[$id] = $deadline;
            }
        }
        if ($newBans === []) {
            return;
        }

        $users = User::query()->whereIntegerInRaw('id', array_keys($newBans))->pluck('group_id', 'id');
        $groups = [];
        foreach ($users as $id => $groupId) {
            $groups[$groupId][$id] = $newBans[$id];
        }
        foreach (Server::all(['id', 'group_ids']) as $server) {
            $bans = [];
            foreach ($server->group_ids ?? [] as $groupId) {
                $bans += $groups[$groupId] ?? [];
            }
            if ($bans !== [] && NodeSyncService::isNodeOnline($server->id)) {
                NodeSyncService::push($server->id, 'sync.devices', ['device_bans' => (object) $bans]);
            }
        }
    }
}
