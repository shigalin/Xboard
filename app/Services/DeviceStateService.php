<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class DeviceStateService
{
    private const PREFIX = 'user_devices:';
    private const INDEX_PREFIX = 'device:node_index:'; // per-node set of userIds
    private const INDEX_VERSION_KEY = 'device:node_index_version';
    private const INDEX_MIGRATION_LOCK_KEY = 'device:node_index_migration_lock';
    private const INDEX_VERSION = 2;
    private const SEQUENCE_PREFIX = 'device:node_sequence:';
    private const SEQUENCE_ALLOCATOR_PREFIX = 'device:node_sequence_allocator:';
    private const REPORT_TYPE_PREFIX = 'device:node_report_full:';
    private const USER_SEQUENCE_PREFIX = 'device:node_user_sequence:';
    private const USER_TOUCH_PREFIX = 'device:online_touch_due:';
    private const DB_SYNC_PENDING_KEY = 'device:db_sync_pending';
    private const SEQUENCE_BLOCK_SIZE = 1000000000;
    private const TTL = 300; // device state ttl
    private const INDEX_TTL = 3600;
    // How long a written online_count is trusted before being rewritten even
    // if unchanged. Must stay well under CleanupOnlineStatus's 10-minute
    // staleness window so online users keep a fresh last_online_at.
    private const COUNT_CACHE_TTL = 270;
    private const TOUCH_TTL = 300;
    private const DB_SYNC_LOCK_TTL = 120;
    private const INDEX_MIGRATION_LOCK_TTL = 600;
    private const WORK_SET_TTL = 600;
    private const CLEAR_TOKEN_TTL = 600;

    /**
     * Release the per-user DB sync lock only when no newer device mutation was
     * observed. Redis executes this atomically, so a trailing update cannot be
     * lost between the revision check and lock release.
     */
    private const RELEASE_DB_SYNC_LOCK_LUA = <<<'LUA'
if redis.call('GET', KEYS[1]) ~= ARGV[1] then
    return 2
end
if redis.call('GET', KEYS[2]) == ARGV[2] then
    redis.call('DEL', KEYS[1])
    return 1
end
return 0
LUA;

    private const SYNC_BATCH_SIZE = 200;
    private const CLEAR_TOKEN_PREFIX = 'device:node_clear_token:';
    private const WORK_SET_PREFIX = 'device:node_work_set:';

    /**
     * Fence older REST/WS reports before applying bounded mutation batches.
     */
    private const BEGIN_NODE_SYNC_LUA = <<<'LUA'
local function decimalGreater(left, right)
    if string.len(left) ~= string.len(right) then
        return string.len(left) > string.len(right)
    end
    return left > right
end

local current = redis.call('GET', ARGV[1])
if current and not decimalGreater(ARGV[3], current) then
    return 0
end
redis.call('SET', ARGV[1], ARGV[3])
redis.call('SET', ARGV[2], ARGV[4])
return 1
LUA;

    private const RESERVE_NODE_SEQUENCE_LUA = <<<'LUA'
local function decimalGreater(left, right)
    if string.len(left) ~= string.len(right) then
        return string.len(left) > string.len(right)
    end
    return left > right
end

local allocator = redis.call('GET', KEYS[1]) or '0'
local applied = redis.call('GET', KEYS[2]) or '0'
if decimalGreater(applied, allocator) then
    redis.call('SET', KEYS[1], applied)
end
return redis.call('INCRBY', KEYS[1], ARGV[1])
LUA;

    /**
     * Refresh at most SYNC_BATCH_SIZE users. Per-user sequences allow an older
     * accepted batch to finish without overwriting a newer overlapping report.
     */
    private const SYNC_NODE_BATCH_LUA = <<<'LUA'
local sequenceKey = ARGV[1]
local reportTypeKey = ARGV[2]
local userSequencePrefix = ARGV[3]
local touchPrefix = ARGV[4]
local indexKey = ARGV[5]
local hashPrefix = ARGV[6]
local nodePrefix = ARGV[7]
local incoming = ARGV[8]
local ttl = tonumber(ARGV[9])
local touchTTL = tonumber(ARGV[10])
local timestamp = ARGV[11]
local devices = cjson.decode(ARGV[12])

local function decimalGreater(left, right)
    if string.len(left) ~= string.len(right) then
        return string.len(left) > string.len(right)
    end
    return left > right
end

local current = redis.call('GET', sequenceKey)
if current ~= incoming and redis.call('GET', reportTypeKey) == '1' then
    return {0}
end

local notifyUsers = {}
for userId, ips in pairs(devices) do
    local userSequenceKey = userSequencePrefix .. userId
    local userSequence = redis.call('GET', userSequenceKey)
    if not userSequence or decimalGreater(incoming, userSequence) then
        local hashKey = hashPrefix .. userId
        local oldIPs = {}
        local oldCount = 0
        for _, field in ipairs(redis.call('HKEYS', hashKey)) do
            if string.sub(field, 1, string.len(nodePrefix)) == nodePrefix then
                oldIPs[string.sub(field, string.len(nodePrefix) + 1)] = true
                oldCount = oldCount + 1
                redis.call('HDEL', hashKey, field)
            end
        end

        local changed = oldCount ~= #ips
        if not changed then
            for _, ip in ipairs(ips) do
                if not oldIPs[ip] then
                    changed = true
                    break
                end
            end
        end

        redis.call('SREM', indexKey, userId)
        if #ips > 0 then
            for _, ip in ipairs(ips) do
                redis.call('HSET', hashKey, nodePrefix .. ip, timestamp)
            end
            redis.call('EXPIRE', hashKey, ttl)
            redis.call('SADD', indexKey, userId)
        end
        local touchKey = touchPrefix .. userId
        if changed then
            redis.call('SETEX', touchKey, touchTTL, '1')
            table.insert(notifyUsers, userId)
        elseif redis.call('SET', touchKey, '1', 'EX', touchTTL, 'NX') then
            table.insert(notifyUsers, userId)
        end
        redis.call('SETEX', userSequenceKey, ttl * 2, incoming)
    end
end

if redis.call('SCARD', indexKey) > 0 then
    redis.call('EXPIRE', indexKey, ttl * 12)
else
    redis.call('DEL', indexKey)
end

local result = {1}
for _, userId in ipairs(notifyUsers) do
    table.insert(result, userId)
end
return result
LUA;

    /** Copy and iterate node membership through a stable, bounded work set. */
    private function copySetInBatches(string $sourceKey, string $workKey): void
    {
        $cursor = null;
        do {
            $scan = Redis::sscan($sourceKey, $cursor, ['count' => 500]);
            if ($scan === false) {
                break;
            }

            [$cursor, $members] = $scan;
            foreach (array_chunk($members, self::SYNC_BATCH_SIZE) as $memberBatch) {
                if ($memberBatch !== []) {
                    Redis::sadd($workKey, ...$memberBatch);
                    Redis::expire($workKey, self::WORK_SET_TTL);
                }
            }
        } while ((int) $cursor !== 0);

        if (Redis::scard($workKey) > 0) {
            Redis::expire($workKey, self::WORK_SET_TTL);
        }
    }

    private function addSetMembersInBatches(string $key, array $members): void
    {
        foreach (array_chunk($members, self::SYNC_BATCH_SIZE) as $memberBatch) {
            if ($memberBatch !== []) {
                Redis::sadd($key, ...$memberBatch);
                Redis::expire($key, self::WORK_SET_TTL);
            }
        }
        if (Redis::scard($key) > 0) {
            Redis::expire($key, self::WORK_SET_TTL);
        }
    }

    private function scanSetBatches(string $key, callable $callback): bool
    {
        $cursor = null;
        do {
            $scan = Redis::sscan($key, $cursor, ['count' => self::SYNC_BATCH_SIZE]);
            if ($scan === false) {
                break;
            }

            [$cursor, $members] = $scan;
            Redis::expire($key, self::WORK_SET_TTL);
            foreach (array_chunk($members, self::SYNC_BATCH_SIZE) as $memberBatch) {
                if ($memberBatch !== []) {
                    if ($callback($memberBatch) === false) {
                        return false;
                    }
                }
            }
        } while ((int) $cursor !== 0);

        return true;
    }

    private const BEGIN_NODE_CLEAR_LUA = <<<'LUA'
local function decimalGreater(left, right)
    if string.len(left) ~= string.len(right) then
        return string.len(left) > string.len(right)
    end
    return left > right
end

local current = redis.call('GET', ARGV[1])
if ARGV[3] == '1' and current and decimalGreater(current, ARGV[4]) then
    return 0
end
redis.call('SETEX', ARGV[2], tonumber(ARGV[6]), ARGV[5])
return 1
LUA;

    private const CLEAR_NODE_BATCH_LUA = <<<'LUA'
local clearTokenKey = ARGV[1]
local userSequencePrefix = ARGV[2]
local indexKey = ARGV[3]
local hashPrefix = ARGV[4]
local nodePrefix = ARGV[5]
local token = ARGV[6]
local conditional = ARGV[7] == '1'
local connectionSequence = ARGV[8]
local userIds = cjson.decode(ARGV[9])

local function decimalGreater(left, right)
    if string.len(left) ~= string.len(right) then
        return string.len(left) > string.len(right)
    end
    return left > right
end

if redis.call('GET', clearTokenKey) ~= token then
    return 0
end
local clearedUsers = {}
for _, userId in ipairs(userIds) do
    local userSequenceKey = userSequencePrefix .. userId
    local userSequence = redis.call('GET', userSequenceKey)
    local shouldClear = false
    if conditional then
        shouldClear = not userSequence or not decimalGreater(userSequence, connectionSequence)
    else
        shouldClear = not userSequence
    end

    if shouldClear then
        local hashKey = hashPrefix .. userId
        for _, field in ipairs(redis.call('HKEYS', hashKey)) do
            if string.sub(field, 1, string.len(nodePrefix)) == nodePrefix then
                redis.call('HDEL', hashKey, field)
            end
        end
        redis.call('SREM', indexKey, userId)
        table.insert(clearedUsers, userId)
        if conditional then
            redis.call('SETEX', userSequenceKey, tonumber(ARGV[10]) * 2, connectionSequence)
        end
    end
end
redis.call('EXPIRE', clearTokenKey, tonumber(ARGV[10]))
local result = {1}
for _, userId in ipairs(clearedUsers) do
    table.insert(result, userId)
end
return result
LUA;

    /**
     * Remove the configured Redis prefix from a physical key returned by SCAN.
     */
    private function removeRedisPrefix(string $key): string
    {
        $prefix = (string) config('database.redis.options.prefix', '');

        return $prefix !== '' && str_starts_with($key, $prefix)
            ? substr($key, strlen($prefix))
            : $key;
    }

    /** Build all node indices once at the worker/deployment boundary. */
    public function completeIndexMigration(): bool
    {
        if ((int) Redis::get(self::INDEX_VERSION_KEY) >= self::INDEX_VERSION) {
            return true;
        }

        $token = bin2hex(random_bytes(16));
        if (!Redis::set(
            self::INDEX_MIGRATION_LOCK_KEY,
            $token,
            'EX',
            self::INDEX_MIGRATION_LOCK_TTL,
            'NX'
        )) {
            return false;
        }

        try {
            $redisPrefix = (string) config('database.redis.options.prefix', '');
            $pattern = $redisPrefix . self::PREFIX . '*';
            $cursor = null;

            do {
                $scan = Redis::scan($cursor, ['match' => $pattern, 'count' => 500]);
                if ($scan === false) {
                    break;
                }

                [$cursor, $keys] = $scan;
                foreach ($keys as $physicalKey) {
                    $key = $this->removeRedisPrefix($physicalKey);
                    if (!str_starts_with($key, self::PREFIX)) {
                        continue;
                    }

                    $userId = substr($key, strlen(self::PREFIX));
                    if ($userId === '' || !ctype_digit($userId)) {
                        continue;
                    }

                    $nodeIds = [];
                    foreach (Redis::hkeys($key) as $field) {
                        $separator = strpos($field, ':');
                        $fieldNodeId = $separator === false ? '' : substr($field, 0, $separator);
                        if ($fieldNodeId !== '' && ctype_digit($fieldNodeId)) {
                            $nodeIds[(int) $fieldNodeId] = true;
                        }
                    }
                    foreach (array_keys($nodeIds) as $nodeId) {
                        $indexKey = self::INDEX_PREFIX . $nodeId;
                        Redis::sadd($indexKey, (int) $userId);
                        Redis::expire($indexKey, self::INDEX_TTL);
                    }
                }
                $lockHeld = (int) Redis::eval(
                    "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('EXPIRE', KEYS[1], ARGV[2]) end return 0",
                    1,
                    self::INDEX_MIGRATION_LOCK_KEY,
                    $token,
                    (string) self::INDEX_MIGRATION_LOCK_TTL
                );
                if ($lockHeld !== 1) {
                    return false;
                }
            } while ((int) $cursor !== 0);

            Redis::set(self::INDEX_VERSION_KEY, self::INDEX_VERSION);
            return true;
        } finally {
            Redis::eval(
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
                1,
                self::INDEX_MIGRATION_LOCK_KEY,
                $token
            );
        }
    }

    private function ensureNodeIndex(int $nodeId): void
    {
        if ((int) Redis::get(self::INDEX_VERSION_KEY) >= self::INDEX_VERSION) {
            return;
        }

        // Before the new long-lived WS worker marks the migration complete,
        // scan on every access. This keeps old workers' legacy writes visible
        // instead of sealing the index on a timer while they may still run.
        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $pattern = $redisPrefix . self::PREFIX . '*';
        $nodeFieldPrefix = "{$nodeId}:";
        $indexKey = self::INDEX_PREFIX . $nodeId;
        $cursor = null;
        do {
            $scan = Redis::scan($cursor, ['match' => $pattern, 'count' => 500]);
            if ($scan === false) {
                break;
            }
            [$cursor, $keys] = $scan;
            foreach ($keys as $physicalKey) {
                $key = $this->removeRedisPrefix($physicalKey);
                $userId = substr($key, strlen(self::PREFIX));
                if ($userId === '' || !ctype_digit($userId)) {
                    continue;
                }
                foreach (Redis::hkeys($key) as $field) {
                    if (str_starts_with($field, $nodeFieldPrefix)) {
                        Redis::sadd($indexKey, (int) $userId);
                        break;
                    }
                }
            }
        } while ((int) $cursor !== 0);
        if (Redis::scard($indexKey) > 0) {
            Redis::expire($indexKey, self::INDEX_TTL);
        }
    }

    /**
     * 批量设置设备
     * 用于 HTTP /alive 和 WebSocket report.devices
     */
    public function setDevices(int $userId, int $nodeId, array $ips): void
    {
        $key = self::PREFIX . $userId;
        $timestamp = time();

        $this->removeNodeDevices($nodeId, $userId);

        // Normalize: strip port suffix and deduplicate
        $ips = array_values(array_unique(array_map([self::class, 'normalizeIP'], $ips)));

        if (!empty($ips)) {
            $fields = [];
            foreach ($ips as $ip) {
                $fields["{$nodeId}:{$ip}"] = $timestamp;
            }
            Redis::hMset($key, $fields);
            Redis::expire($key, self::TTL);

            $indexKey = self::INDEX_PREFIX . $nodeId;
            Redis::sadd($indexKey, $userId);
            Redis::expire($indexKey, self::INDEX_TTL);
        }

        $this->queueUpdates([$userId]);
    }

    /**
     * Apply a sequenced REST delta or WS full snapshot in bounded batches.
     * Per-user sequence fences keep overlapping batches correctly ordered.
     * Returns users needing an online_count refresh, or null when superseded.
     */
    public function syncNodeDevices(int $nodeId, array $devices, int $sequence, bool $fullSnapshot): ?array
    {
        if ($sequence <= 0) {
            throw new \InvalidArgumentException('device sequence must be positive');
        }

        $this->ensureNodeIndex($nodeId);

        $normalized = [];
        foreach ($devices as $userId => $ips) {
            if (!is_numeric($userId) || !is_array($ips)) {
                continue;
            }

            $normalizedUserId = (int) $userId;
            if ($normalizedUserId <= 0) {
                continue;
            }

            $normalizedIps = [];
            foreach ($ips as $ip) {
                if (!is_scalar($ip)) {
                    continue;
                }
                $ip = self::normalizeIP((string) $ip);
                if ($ip !== '') {
                    $normalizedIps[] = $ip;
                }
            }
            $normalized[$normalizedUserId] = array_values(array_unique($normalizedIps));
        }

        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $sequenceKey = $redisPrefix . self::SEQUENCE_PREFIX . $nodeId;
        $reportTypeKey = $redisPrefix . self::REPORT_TYPE_PREFIX . $nodeId;
        $userSequenceKey = $redisPrefix . self::USER_SEQUENCE_PREFIX . $nodeId . ':';
        $touchPrefix = $redisPrefix . self::USER_TOUCH_PREFIX;
        $indexKey = self::INDEX_PREFIX . $nodeId;
        $physicalIndexKey = $redisPrefix . $indexKey;
        $result = Redis::eval(
            self::BEGIN_NODE_SYNC_LUA,
            0,
            $sequenceKey,
            $reportTypeKey,
            (string) $sequence,
            $fullSnapshot ? '1' : '0'
        );

        if ((int) $result !== 1) {
            return null;
        }

        $affectedUserIds = [];
        $timestamp = (string) time();
        $workKey = self::WORK_SET_PREFIX . $nodeId . ':' . bin2hex(random_bytes(16));
        $processBatch = function (array $userIdBatch) use (
            &$affectedUserIds,
            $normalized,
            $sequenceKey,
            $reportTypeKey,
            $userSequenceKey,
            $touchPrefix,
            $physicalIndexKey,
            $redisPrefix,
            $nodeId,
            $sequence,
            $timestamp
        ): bool {
            $deviceBatch = [];
            foreach ($userIdBatch as $userId) {
                $userId = (int) $userId;
                $deviceBatch[$userId] = $normalized[$userId] ?? [];
            }

            $batchResult = Redis::eval(
                self::SYNC_NODE_BATCH_LUA,
                0,
                $sequenceKey,
                $reportTypeKey,
                $userSequenceKey,
                $touchPrefix,
                $physicalIndexKey,
                $redisPrefix . self::PREFIX,
                "{$nodeId}:",
                (string) $sequence,
                (string) self::TTL,
                (string) self::TOUCH_TTL,
                $timestamp,
                json_encode((object) $deviceBatch, JSON_THROW_ON_ERROR)
            );

            if (!is_array($batchResult) || (int) ($batchResult[0] ?? 0) !== 1) {
                return false;
            }
            $batchUserIds = array_values(array_unique(array_map(
                'intval',
                array_slice($batchResult, 1)
            )));
            $this->queueUpdates($batchUserIds);
            $affectedUserIds = array_merge($affectedUserIds, $batchUserIds);
            return true;
        };

        try {
            if ($fullSnapshot) {
                $this->copySetInBatches($indexKey, $workKey);
                $this->addSetMembersInBatches($workKey, array_keys($normalized));
                if (!$this->scanSetBatches($workKey, $processBatch)) {
                    return null;
                }
            } else {
                foreach (array_chunk(array_keys($normalized), self::SYNC_BATCH_SIZE) as $userIdBatch) {
                    if (!$processBatch($userIdBatch)) {
                        return null;
                    }
                }
            }
        } finally {
            Redis::del($workKey);
        }

        return array_values(array_unique(array_map('intval', $affectedUserIds)));
    }

    /**
     * 获取某节点的所有设备数据
     * 返回: {userId: [ip1, ip2, ...], ...}
     *
     * 通过每节点的 userId 索引集合读取，避免在热路径使用 Redis KEYS。
     * 首次升级通过 SCAN 回填旧 hash；之后仅维护索引，过期 hash 对应的
     * 残留成员在这里惰性清理。
     */
    public function getNodeDevices(int $nodeId): array
    {
        $this->ensureNodeIndex($nodeId);

        $indexKey = self::INDEX_PREFIX . $nodeId;
        $prefix = "{$nodeId}:";
        $result = [];
        $now = time();

        foreach (Redis::smembers($indexKey) as $uid) {
            $uid = (int) $uid;
            $data = Redis::hgetall(self::PREFIX . $uid);
            foreach ($data as $field => $timestamp) {
                if (str_starts_with($field, $prefix)) {
                    if ($now - (int) $timestamp <= self::TTL) {
                        $ip = substr($field, strlen($prefix));
                        $result[$uid][] = $ip;
                    } else {
                        Redis::hdel(self::PREFIX . $uid, $field);
                    }
                }
            }
            if (!isset($result[$uid])) {
                Redis::srem($indexKey, $uid);
            }
        }

        return $result;
    }

    public function getNodeSequence(int $nodeId): int
    {
        return (int) Redis::get(self::SEQUENCE_PREFIX . $nodeId);
    }

    public function hasNodeSequence(int $nodeId): bool
    {
        return (bool) Redis::exists(self::SEQUENCE_PREFIX . $nodeId);
    }

    public function reserveNodeSequence(int $nodeId): int
    {
        return (int) Redis::eval(
            self::RESERVE_NODE_SEQUENCE_LUA,
            2,
            self::SEQUENCE_ALLOCATOR_PREFIX . $nodeId,
            self::SEQUENCE_PREFIX . $nodeId,
            (string) self::SEQUENCE_BLOCK_SIZE
        );
    }

    /**
     * 删除某节点某用户的设备
     */
    public function removeNodeDevices(int $nodeId, int $userId): void
    {
        $key = self::PREFIX . $userId;
        $prefix = "{$nodeId}:";

        foreach (Redis::hkeys($key) as $field) {
            if (str_starts_with($field, $prefix)) {
                Redis::hdel($key, $field);
            }
        }

        Redis::srem(self::INDEX_PREFIX . $nodeId, $userId);
    }

    /**
     * 清除节点所有设备数据（用于节点断开连接）
     */
    public function clearAllNodeDevices(int $nodeId, ?int $connectionSequence = null): array
    {
        $this->ensureNodeIndex($nodeId);

        $redisPrefix = (string) config('database.redis.options.prefix', '');
        $sequenceKey = $redisPrefix . self::SEQUENCE_PREFIX . $nodeId;
        $clearTokenKey = $redisPrefix . self::CLEAR_TOKEN_PREFIX . $nodeId;
        $userSequenceKey = $redisPrefix . self::USER_SEQUENCE_PREFIX . $nodeId . ':';
        $indexKey = self::INDEX_PREFIX . $nodeId;
        $physicalIndexKey = $redisPrefix . $indexKey;
        $token = bin2hex(random_bytes(16));
        $conditional = $connectionSequence === null ? '0' : '1';
        $result = Redis::eval(
            self::BEGIN_NODE_CLEAR_LUA,
            0,
            $sequenceKey,
            $clearTokenKey,
            $conditional,
            (string) ($connectionSequence ?? 0),
            $token,
            (string) self::CLEAR_TOKEN_TTL
        );

        if ((int) $result !== 1) {
            return [];
        }

        $affectedUserIds = [];
        $workKey = self::WORK_SET_PREFIX . $nodeId . ':' . bin2hex(random_bytes(16));
        try {
            $this->copySetInBatches($indexKey, $workKey);
            $this->scanSetBatches($workKey, function (array $userIdBatch) use (
                &$affectedUserIds,
                $clearTokenKey,
                $userSequenceKey,
                $physicalIndexKey,
                $redisPrefix,
                $nodeId,
                $token,
                $conditional,
                $connectionSequence
            ): bool {
                $batchResult = Redis::eval(
                    self::CLEAR_NODE_BATCH_LUA,
                    0,
                    $clearTokenKey,
                    $userSequenceKey,
                    $physicalIndexKey,
                    $redisPrefix . self::PREFIX,
                    "{$nodeId}:",
                    $token,
                    $conditional,
                    (string) ($connectionSequence ?? 0),
                    json_encode(array_values($userIdBatch), JSON_THROW_ON_ERROR),
                    (string) self::CLEAR_TOKEN_TTL
                );
                if (!is_array($batchResult) || (int) ($batchResult[0] ?? 0) !== 1) {
                    return false;
                }
                $affectedUserIds = array_merge($affectedUserIds, array_slice($batchResult, 1));
                return true;
            });
        } finally {
            Redis::del($workKey);
            Redis::eval(
                "if redis.call('GET', ARGV[1]) == ARGV[2] then return redis.call('DEL', ARGV[1]) end return 0",
                0,
                $clearTokenKey,
                $token
            );
        }

        $affectedUserIds = array_values(array_unique(array_map('intval', $affectedUserIds)));
        $this->queueUpdates($affectedUserIds);

        return $affectedUserIds;
    }

    /**
     * get user device count (deduplicated by IP, filter expired data)
     */
    public function getDeviceCount(int $userId): int
    {
        $data = Redis::hgetall(self::PREFIX . $userId);
        $now = time();
        $ips = [];

        foreach ($data as $field => $timestamp) {
            if ($now - $timestamp <= self::TTL) {
                $ips[] = substr($field, strpos($field, ':') + 1);
            }
        }

        return count(array_unique($ips));
    }

    /**
     * get user device count (for alivelist interface)
     */
    public function getAliveList(Collection $users): array
    {
        if ($users->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($users as $user) {
            $count = $this->getDeviceCount($user->id);
            if ($count > 0) {
                $result[$user->id] = $count;
            }
        }

        return $result;
    }

    /**
     * get devices of multiple users (for sync.devices, filter expired data)
     */
    public function getUsersDevices(array $userIds): array
    {
        $result = [];
        $now = time();
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        $rows = Redis::pipeline(function ($pipe) use ($userIds): void {
            foreach ($userIds as $userId) {
                $pipe->hgetall(self::PREFIX . $userId);
            }
        });
        foreach ($userIds as $index => $userId) {
            $data = $rows[$index] ?? [];
            if (!empty($data)) {
                $ips = [];
                foreach ($data as $field => $timestamp) {
                    if ($now - $timestamp <= self::TTL) {
                        $ips[] = substr($field, strpos($field, ':') + 1);
                    }
                }
                if (!empty($ips)) {
                    $result[$userId] = array_values(array_unique($ips));
                }
            }
        }

        return $result;
    }

    /**
     * Strip port from IP address: "1.2.3.4:12345" → "1.2.3.4", "[::1]:443" → "::1"
     */
    private static function normalizeIP(string $ip): string
    {
        // [IPv6]:port
        if (preg_match('/^\[(.+)\]:\d+$/', $ip, $m)) {
            return $m[1];
        }
        // IPv4:port
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+):\d+$/', $ip, $m)) {
            return $m[1];
        }
        return $ip;
    }

    /**
     * Sync online_count to DB only when the deduplicated count changed, or the
     * last write is older than COUNT_CACHE_TTL. A per-user revision plus lock
     * coalesces concurrent node reports without losing the trailing state.
     */
    public function notifyUpdate(int $userId): void
    {
        $cacheKey = "device:last_count:v2:{$userId}";
        $revisionKey = "device:db_revision:{$userId}";
        $lockKey = "device:db_sync_lock:{$userId}";

        Redis::incr($revisionKey);
        Redis::expire($revisionKey, self::COUNT_CACHE_TTL * 2);

        $count = $this->getDeviceCount($userId);
        if ((string) $count === (string) Redis::get($cacheKey)) {
            return;
        }

        $token = bin2hex(random_bytes(16));
        if (!Redis::set($lockKey, $token, 'EX', self::DB_SYNC_LOCK_TTL, 'NX')) {
            return;
        }

        $retryAfterLostLock = false;
        try {
            do {
                $revision = (string) Redis::get($revisionKey);
                $count = $this->getDeviceCount($userId);

                if ((string) $count !== (string) Redis::get($cacheKey)) {
                    User::query()
                        ->whereKey($userId)
                        ->update([
                            'online_count' => $count,
                            'last_online_at' => now(),
                        ]);

                    Redis::setex($cacheKey, self::COUNT_CACHE_TTL, $count);
                }

                $released = (int) Redis::eval(
                    self::RELEASE_DB_SYNC_LOCK_LUA,
                    2,
                    $lockKey,
                    $revisionKey,
                    $token,
                    $revision
                );
                if ($released === 2) {
                    $retryAfterLostLock = true;
                    break;
                }
            } while ($released === 0);
        } finally {
            Redis::eval(
                "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) end return 0",
                1,
                $lockKey,
                $token
            );
        }

        if ($retryAfterLostLock) {
            $this->notifyUpdate($userId);
        }
    }

    public function queueUpdates(array $userIds): void
    {
        $userIds = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn (int $userId): bool => $userId > 0
        )));
        if ($userIds !== []) {
            Redis::sadd(self::DB_SYNC_PENDING_KEY, ...$userIds);
        }
    }

    public function flushPendingUpdates(int $limit = 100): int
    {
        if ($limit <= 0) {
            return 0;
        }

        $userIds = Redis::spop(self::DB_SYNC_PENDING_KEY, $limit);
        if (!is_array($userIds)) {
            $userIds = $userIds === false || $userIds === null ? [] : [$userIds];
        }
        foreach ($userIds as $index => $userId) {
            try {
                $this->notifyUpdate((int) $userId);
            } catch (\Throwable $e) {
                $this->queueUpdates(array_slice($userIds, $index));
                throw $e;
            }
        }

        return count($userIds);
    }
}
