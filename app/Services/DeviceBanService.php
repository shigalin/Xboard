<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use App\Support\BestEffortLog;
use App\Utils\Helper;
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
            BestEffortLog::write('warning', 'Could not load device bans for device snapshot', ['error' => $error::class]);
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
        $this->publishNewBans(array_intersect_key($reports, $eligible), 'node', (int) $node->id);
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
            $details = [];
            foreach ($this->getAdmissionIPs(array_keys($limits)) as $id => $ips) {
                $count = DeviceStateService::countDevices($ips);
                if ($count > $limits[$id]) {
                    $overLimit[$id] = 0;
                    // The IPs counted: fresh reports from every node, merged.
                    $details[$id] = [
                        'device_limit' => (int) $limits[$id],
                        'device_count' => $count,
                        'ips' => $ips,
                    ];
                }
            }
            $this->publishNewBans($overLimit, 'panel', (int) $nodeId, $details);
        } catch (\Throwable $error) {
            // The IPs were saved. Let the next report retry even if the set is unchanged.
            try {
                app(DeviceStateService::class)->retryDeviceCheck($userIds);
            } catch (\Throwable $retryError) {
                BestEffortLog::write('warning', 'Could not reschedule device limit check', [
                    'node_id' => $nodeId,
                    'error' => $retryError::class,
                ]);
            }
            BestEffortLog::write('warning', 'Device limit check failed after saving the report', [
                'node_id' => $nodeId,
                'error' => $error::class,
            ]);
        }
    }

    /**
     * The banned user IDs each stored group value matches, keyed like
     * $values, compared as full sync's whereIn compares them. One statement
     * covers up to Server::GROUP_VALUE_COLUMNS values. If the database rejects a value (PostgreSQL rejects
     * "abc" for an integer), each value is compared on its own instead and a
     * rejected one maps to null.
     *
     * @param list<int> $userIds
     * @param array<string, mixed> $values
     * @return array<string, list<int>|null>
     */
    private static function bannedUsersByGroupValue(array $userIds, array $values): array
    {
        $matched = [];
        // One column per value, in chunks that stay under every database's
        // result column limit.
        foreach (array_chunk($values, Server::GROUP_VALUE_COLUMNS, true) as $chunk) {
            $keys = array_keys($chunk);
            $columns = array_map(fn ($index) => "CASE WHEN group_id IN (?) THEN 1 ELSE 0 END AS v{$index}", array_keys($keys));
            $rows = Server::compareGroupValues(fn () => User::query()->toBase()
                ->whereIntegerInRaw('id', $userIds)
                ->select('id')
                ->selectRaw(implode(', ', $columns), array_values($chunk))
                ->get(), null);
            foreach ($keys as $index => $key) {
                $matched[$key] = $rows === null
                    ? Server::compareGroupValues(fn () => User::query()->whereIntegerInRaw('id', $userIds)
                        ->whereIn('group_id', [$chunk[$key]])->pluck('id')->all(), null)
                    : $rows->filter(fn ($row) => (int) $row->{"v{$index}"} === 1)->pluck('id')->all();
            }
        }
        return $matched;
    }

    /**
     * Start bans that are not already active and push them to the user's nodes.
     *
     * Attempt one log entry per started ban. $source is "node" for a node's
     * rejection report and "panel" when the panel detects a combined count
     * above the limit. $nodeId identifies the reporting node in either case.
     * Deadlines are Unix milliseconds on the panel clock.
     *
     * @param array<int, int> $reports user ID => proposed deadline, 0 for the default
     * @param array<int, array<string, mixed>> $details extra log context per user
     */
    private function publishNewBans(array $reports, string $source, int $nodeId, array $details = []): void
    {
        $newBans = [];
        foreach ($reports as $id => $until) {
            // Use the panel clock for both ban deadlines and IP observations.
            $now = (int) floor(microtime(true) * 1000);
            $deadline = (int) Redis::eval(self::START_BAN, 1, self::KEY, $id, $until, $now);
            if ($deadline > 0) {
                $newBans[$id] = $deadline;
                BestEffortLog::write('warning', '[DeviceBan] Device ban started', [
                    'user_id' => (int) $id,
                    'source' => $source,
                    'node_id' => $nodeId,
                    'until' => date('Y-m-d H:i:s', intdiv($deadline, 1000)),
                    'until_ms' => $deadline,
                ] + ($details[$id] ?? []));
            }
        }
        if ($newBans === []) {
            return;
        }

        // Collect each node's stored group values, then match every newly
        // banned user against every distinct value in one statement with the
        // IN full sync runs, so values such as "01" or true reach the users
        // full sync gives the node. Only nodes that get bans are checked for
        // being online.
        // The node and user reads, chunks included, share one snapshot, so a
        // user changing group between two reads is matched in exactly one.
        $bannedIds = array_keys($newBans);
        [$nodeValues, $matched] = Helper::readSnapshot(function () use ($bannedIds) {
            $nodeValues = [];
            $values = [];
            foreach (Server::all(['id', 'group_ids']) as $server) {
                foreach ($server->syncGroupIds() as $value) {
                    if ($value !== null) {
                        $key = Server::groupValueKey($value);
                        $values[$key] = $value;
                        $nodeValues[$server->id][$key] = true;
                    }
                }
            }
            return [$nodeValues, self::bannedUsersByGroupValue($bannedIds, $values)];
        });
        foreach ($nodeValues as $targetNodeId => $keys) {
            $bans = [];
            foreach (array_keys($keys) as $key) {
                if ($matched[$key] === null) {
                    // The database rejects this value; full sync fails for the node.
                    continue 2;
                }
                $bans += array_intersect_key($newBans, array_flip($matched[$key]));
            }
            if ($bans !== [] && NodeSyncService::isNodeOnline($targetNodeId)) {
                NodeSyncService::push($targetNodeId, 'sync.devices', ['device_bans' => (object) $bans]);
            }
        }
    }
}
