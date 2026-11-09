<?php

namespace App\WebSocket;

use App\Models\Server;
use App\Services\DeviceBanService;
use App\Services\DeviceStateService;
use App\Services\NodeRegistry;
use App\Services\ServerService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Workerman\Connection\TcpConnection;

class NodeEventHandlers
{
    /**
     * Handle pong heartbeat
     */
    public static function handlePong(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        Cache::put("node_ws_alive:{$nodeId}", true, 86400);
    }

    /**
     * Handle node status update
     */
    public static function handleNodeStatus(TcpConnection $conn, int $nodeId, array $data): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $nodeType = strtoupper($node->type);
        Cache::put(\App\Utils\CacheKey::get('SERVER_' . $nodeType . '_LAST_CHECK_AT', $nodeId), time(), 3600);
        ServerService::updateMetrics($node, $data);

        Log::debug("[WS] Node#{$nodeId} status updated");
    }

    /**
     * Handle device report from node
     * 
     * 数据格式: {"event": "report.devices", "data": {userId: [ip1, ip2, ...], ...}}
     */
    public static function handleDeviceReport(TcpConnection $conn, int $nodeId, array $data): void
    {
        $service = app(DeviceStateService::class);

        $sequence = max(0, (int) ($data['device_sequence'] ?? 0));

        if (isset($data['devices']) && is_array($data['devices'])) {
            $data = $data['devices'];
        }

        $affectedUserIds = $service->syncNodeDevices($nodeId, $data, $sequence, true);
        if ($affectedUserIds === null) {
            Log::debug("[WS] Node#{$nodeId} ignored stale device report", ['sequence' => $sequence]);
            return;
        }

        if ($sequence > 0) {
            $deviceSequences = $conn->deviceSequences ?? [];
            $deviceSequences[$nodeId] = $sequence;
            $conn->deviceSequences = $deviceSequences;
        }

        Redis::sadd('device:push_pending_nodes', $nodeId);
        if ($affectedUserIds !== []) {
            app(DeviceBanService::class)->checkReportedDevices($nodeId, $data, $affectedUserIds);
        }
        Log::debug("[WS] Node#{$nodeId} applied device report", [
            'sequence' => $sequence,
            'reported_users' => count($data),
            'affected_users' => count($affectedUserIds),
        ]);
    }

    /**
     * Handle device state request from node
     */
    public static function handleDeviceRequest(TcpConnection $conn, int $nodeId, array $data = []): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();

        $snapshot = app(DeviceBanService::class)->getDeviceSnapshot($userIds);
        NodeRegistry::send($nodeId, 'sync.devices', $snapshot);

        Log::debug("[WS] Node#{$nodeId} requested devices, sent " . count($snapshot['users']) . " users");
    }

    /**
     * Push device state to node
     */
    public static function pushDeviceStateToNode(int $nodeId): void
    {
        $node = Server::find($nodeId);
        if (!$node) return;

        $users = ServerService::getAvailableUsers($node);
        $userIds = $users->pluck('id')->toArray();
        $snapshot = app(DeviceBanService::class)->getDeviceSnapshot($userIds);
        NodeRegistry::send($nodeId, 'sync.devices', $snapshot);

        Log::debug("[WS] Pushed device state to node#{$nodeId}: " . count($snapshot['users']) . " users");
    }

    /**
     * Push full config + users to newly connected node
     */
    public static function pushFullSync(TcpConnection $conn, Server $node): void
    {
        $nodeId = (int) $node->id;

        // Push config
        $config = ServerService::buildNodeConfig($node);
        NodeRegistry::send($nodeId, 'sync.config', [
            'config' => $config,
        ]);

        // Push users
        $users = ServerService::getAvailableUsers($node)->toArray();
        NodeRegistry::send($nodeId, 'sync.users', [
            'users' => $users,
        ]);

        Log::info("[WS] Full sync pushed to node#{$nodeId}", [
            'users' => count($users),
        ]);
    }
}
