<?php

namespace App\Http\Controllers\V2\Admin\Server;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ManageController extends Controller
{
    public function getNodes(Request $request)
    {
        // The nodes, their groups (plain IDs in one query, legacy values one
        // by one), parents and today's traffic share one snapshot, so a node
        // never shows one group's old name next to another's new one.
        $servers = Helper::readSnapshot(function () {
            // Today's raw upload + download from the daily node stats, which
            // StatServerJob writes on the same queue as the node's total counters.
            $todayTraffic = StatServer::where('record_type', 'd')
                ->where('record_at', strtotime(date('Y-m-d')))
                ->groupBy('server_id')
                ->selectRaw('server_id, SUM(u + d) as total')
                ->pluck('total', 'server_id');
            $groupCache = [];
            $servers = ServerService::getAllServers();
            Server::primeGroupValueCache($servers, $groupCache);
            return $servers->map(function ($item) use ($todayTraffic, &$groupCache) {
                [$item['group_ids'], $item['groups']] = self::listedGroups($item, $groupCache);
                // The embedded parent's group_ids get the same conversion, so a
                // legacy value (such as "013", or INF, which JSON cannot encode)
                // never reaches the response raw.
                $parent = $item->parent;
                if ($parent !== null) {
                    [$parent['group_ids']] = self::listedGroups($parent, $groupCache);
                }
                $item['parent'] = $parent;
                $item['today_traffic'] = (int) ($todayTraffic[$item->id] ?? 0);
                return $item;
            });
        });
        return $this->success($servers);
    }

    /**
     * The groups full sync gives a node, as matched by the database. Returns
     * group_ids as the unique string list the admin form and group filter
     * expect (matched groups by their ID, and values with no group row as
     * they are, since users can still be in such a group) and the groups full
     * sync serves (name and ID, ordered by ID), read with the matches. A value the database cannot compare with an
     * integer makes full sync fail on that database, so the node then serves
     * no group, though group_ids still lists every value for the form. A
     * malformed legacy row cannot fail the list or break filtering. The list
     * describes the stored value rather than reproduce it ("01" is listed as
     * group "1"), so the admin form sends group_ids only when the admin
     * changed them, and save() writes whatever is sent.
     *
     * @return array{0: list<string>, 1: list<array{name: string, id: int}>}
     */
    private static function listedGroups(Server $server, array &$cache): array
    {
        $matches = $server->groupValueMatches($cache);
        // Group 0 is a valid ID; only null means no group row.
        $existing = array_values(array_unique(array_filter(array_column($matches, 'group'), fn ($id) => $id !== null)));
        $unmatched = array_map(fn ($match) => (string) $match['value'],
            array_filter($matches, fn ($match) => $match['group'] === null));
        $groupIds = array_values(array_unique(array_filter(
            [...array_map('strval', $existing), ...$unmatched], fn ($id) => $id !== '')));
        $served = [];
        if (!in_array(false, array_column($matches, 'comparable'), true)) {
            foreach ($matches as $match) {
                if ($match['group'] !== null) {
                    $served[$match['group']] = ['name' => $match['name'], 'id' => $match['group']];
                }
            }
            ksort($served);
        }
        return [$groupIds, array_values($served)];
    }

    public function getOnlineUsers(Request $request, DeviceStateService $devices)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1',
        ]);
        $server = Server::find($params['id']);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        // Match Server::online's immediate parent fallback, not child-node IDs
        // or a recursive aggregation of all servers on the same machine.
        $sourceNodeId = (int) ($server->parent_id ?: $server->id);
        if ($server->parent_id && !Server::whereKey($sourceNodeId)->exists()) {
            return $this->fail([400202, '父节点不存在']);
        }

        $states = $devices->getNodeOnlineUserStates($sourceNodeId);
        $list = [];
        if ($states !== []) {
            foreach (User::query()->whereIntegerInRaw('id', array_keys($states))
                ->orderBy('id')->toBase()->get(['id', 'email']) as $user) {
                $list[] = [
                    'id' => (int) $user->id,
                    'email' => $user->email,
                    'online_ip_count' => $states[(int) $user->id]['online_ip_count'],
                    'last_seen_at' => $states[(int) $user->id]['last_seen_at'],
                ];
            }
        }

        return $this->success([
            'list' => $list,
            'total' => count($list),
            // The existing count is a traffic report snapshot, not the fresh
            // device-user count. Keep both explicit instead of equating them.
            'reported_online' => (int) $server->online,
            'source_node_id' => $sourceNodeId,
        ]);
    }

    public function sort(Request $request)
    {
        ini_set('post_max_size', '1m');
        $params = $request->validate([
            '*.id' => 'numeric',
            '*.order' => 'numeric'
        ]);

        try {
            DB::beginTransaction();
            collect($params)->each(function ($item) {
                if (isset($item['id']) && isset($item['order'])) {
                    Server::where('id', $item['id'])->update(['sort' => $item['order']]);
                }
            });
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '保存失败']);

        }
        return $this->success(true);
    }

    public function save(ServerSave $request)
    {
        $params = $request->validated();
        if ($request->input('id')) {
            $server = Server::find($request->input('id'));
            if (!$server) {
                return $this->fail([400202, '服务器不存在']);
            }
            try {
                $server->update($params);
                return $this->success(true);
            } catch (\Exception $e) {
                Log::error($e);
                return $this->fail([500, '保存失败']);
            }
        }

        try {
            Server::create($params);
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '创建失败']);
        }
    }

    public function update(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer',
            'show' => 'nullable|integer',
            'machine_id' => 'nullable|integer',
            'enabled' => 'nullable|boolean',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        if (array_key_exists('show', $params)) {
            $server->show = (int) $params['show'];
        }
        if (array_key_exists('machine_id', $params)) {
            $server->machine_id = $params['machine_id'] ?: null;
        }
        if (array_key_exists('enabled', $params)) {
            $server->enabled = (bool) $params['enabled'];
        }

        if (!$server->save()) {
            return $this->fail([500, '保存失败']);
        }

        return $this->success(true);
    }

    /**
     * 删除
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function drop(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);
        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }
        if ($server->delete() === false) {
            return $this->fail([500, '删除失败']);
        }

        return $this->success(true);
    }

    /**
     * 批量删除节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchDelete(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要删除的节点']);
        }

        try {
            $deleted = Server::whereIn('id', $ids)->delete();
            if ($deleted === false) {
                return $this->fail([500, '批量删除失败']);
            }
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量删除失败']);
        }
    }

    /**
     * 重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function resetTraffic(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
        ]);

        $server = Server::find($request->id);
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        try {
            $server->u = 0;
            $server->d = 0;
            $server->save();
            
            Log::info("Server {$server->id} ({$server->name}) traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '重置失败']);
        }
    }

    /**
     * 批量重置节点流量
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function batchResetTraffic(Request $request)
    {
        $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
        ]);

        $ids = $request->input('ids');
        if (empty($ids)) {
            return $this->fail([400, '请选择要重置的节点']);
        }

        try {
            Server::whereIn('id', $ids)->update([
                'u' => 0,
                'd' => 0,
            ]);
            
            Log::info("Servers " . implode(',', $ids) . " traffic reset by admin");
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量重置失败']);
        }
    }

    /**
     * 批量更新节点属性（show等）
     */
    public function batchUpdate(Request $request)
    {
        $params = $request->validate([
            'ids' => 'required|array',
            'ids.*' => 'integer',
            'show' => 'nullable|integer|in:0,1',
            'enabled' => 'nullable|boolean',
            'machine_id' => 'nullable|integer',
        ]);

        $ids = $params['ids'];
        if (empty($ids)) {
            return $this->fail([400, '请选择要更新的节点']);
        }

        $update = [];
        if (array_key_exists('show', $params) && $params['show'] !== null) {
            $update['show'] = (int) $params['show'];
        }
        if (array_key_exists('enabled', $params) && $params['enabled'] !== null) {
            $update['enabled'] = (bool) $params['enabled'];
        }
        if (array_key_exists('machine_id', $params)) {
            $update['machine_id'] = $params['machine_id'] ?: null;
        }

        if (empty($update)) {
            return $this->fail([400, '没有可更新的字段']);
        }

        try {
            $servers = Server::whereIn('id', $ids)->get();
            DB::transaction(function () use ($servers, $update) {
                /** @var Server $server */
                foreach ($servers as $server) {
                    $server->update($update);
                }
            });
            return $this->success(true);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '批量更新失败']);
        }
    }

    /**
     * 复制节点
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function copy(Request $request)
    {
        $server = Server::find($request->input('id'));
        if (!$server) {
            return $this->fail([400202, '服务器不存在']);
        }

        $copiedServer = $server->replicate();
        $copiedServer->show = 0;
        $copiedServer->code = null;
        $copiedServer->u = 0;
        $copiedServer->d = 0;
        $copiedServer->save();

        return $this->success(true);
    }

    /**
     * Generate ECH (Encrypted Client Hello) key pair.
     * Returns PEM-encoded ECH key (server-side) and ECH config (client-side).
     */
    public function generateEchKey(Request $request)
    {
        $publicName = $request->input('public_name', 'ech.example.com');
        if (strlen($publicName) < 1 || strlen($publicName) > 253) {
            throw new ApiException('public_name must be a valid domain (1-253 bytes)');
        }

        // Generate X25519 key pair
        $privateKey = random_bytes(32);
        $publicKey = sodium_crypto_scalarmult_base($privateKey);

        $configId = random_int(0, 255);

        // Build ECHConfigContents (draft-ietf-tls-esni-18)
        $contents = '';
        $contents .= pack('C', $configId);                // config_id
        $contents .= pack('n', 0x0020);                   // kem_id: DHKEM(X25519)
        $contents .= pack('n', 32) . $publicKey;          // public_key (length-prefixed)
        // cipher_suites: 2 suites × 4 bytes = 8 bytes
        $contents .= pack('n', 8);                        // cipher_suites byte length
        $contents .= pack('nn', 0x0001, 0x0001);          // HKDF-SHA256 + AES-128-GCM
        $contents .= pack('nn', 0x0001, 0x0003);          // HKDF-SHA256 + ChaCha20Poly1305
        $contents .= pack('C', 0);                        // max_name_length
        $contents .= pack('C', strlen($publicName)) . $publicName;
        $contents .= pack('n', 0);                        // extensions: empty

        // ECHConfig = version(2) + length(2) + contents
        $echConfig = pack('n', 0xfe0d) . pack('n', strlen($contents)) . $contents;

        // ECHConfigList = total_length(2) + configs
        $echConfigList = pack('n', strlen($echConfig)) . $echConfig;

        // ECH Keys = private_key_len(2) + key(32) + config_len(2) + config
        $echKeysPayload = pack('n', 32) . $privateKey . pack('n', strlen($echConfig)) . $echConfig;

        $keyPem = "-----BEGIN ECH KEYS-----\n"
            . chunk_split(base64_encode($echKeysPayload), 64, "\n")
            . "-----END ECH KEYS-----";

        $configPem = "-----BEGIN ECH CONFIGS-----\n"
            . chunk_split(base64_encode($echConfigList), 64, "\n")
            . "-----END ECH CONFIGS-----";

        return $this->success([
            'key' => $keyPem,
            'config' => $configPem,
        ]);
    }
}
