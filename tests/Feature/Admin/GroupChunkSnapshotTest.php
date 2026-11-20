<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\User;
use App\Services\DeviceBanService;
use App\Utils\Helper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The ranking and device-ban pushes compare stored group values with users in
 * chunks of Server::GROUP_VALUE_COLUMNS. A second connection moves a user to
 * another group and commits right after the first chunk's statement, as a
 * concurrent plan change would. Both chunks must read the same snapshot, so
 * the user is seen in exactly one group. The data is committed, so this test
 * does not use RefreshDatabase: it migrates, then deletes its rows. SQLite
 * uses a shared WAL file instead of :memory:.
 */
class GroupChunkSnapshotTest extends TestCase
{
    private ?string $sqliteFile = null;
    private array $userIds = [];
    private array $serverIds = [];
    private array $groupIds = [];
    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('database.connections.' . config('database.default'));
        if ($config['driver'] === 'sqlite') {
            $this->sqliteFile = tempnam(sys_get_temp_dir(), 'xboard-chunks-');
            $config = [
                'driver' => 'sqlite', 'database' => $this->sqliteFile, 'prefix' => '',
                'foreign_key_constraints' => true, 'busy_timeout' => 1000, 'journal_mode' => 'wal',
            ];
            config(['database.connections.chunks_main' => $config, 'database.default' => 'chunks_main']);
        }
        config(['database.connections.chunks_other' => $config]);
        Artisan::call('migrate', ['--force' => true]);
        $this->redisPrefix = 'group_chunks_test:' . bin2hex(random_bytes(8)) . ':';
        config(['database.redis.options.prefix' => $this->redisPrefix]);
        $this->app->forgetInstance('redis');
        Redis::clearResolvedInstance('redis');
    }

    protected function tearDown(): void
    {
        try {
            foreach (Redis::keys('*') as $key) {
                if (str_starts_with($key, $this->redisPrefix)) {
                    Redis::del(substr($key, strlen($this->redisPrefix)));
                }
            }
            StatServer::whereIn('server_id', $this->serverIds)->delete();
            Server::withoutEvents(fn () => Server::whereIn('id', $this->serverIds)->delete());
            ServerGroup::whereIn('id', $this->groupIds)->delete();
            User::whereIn('id', $this->userIds)->delete();
            DB::disconnect('chunks_other');
            if ($this->sqliteFile !== null) {
                DB::disconnect('chunks_main');
            }
        } finally {
            parent::tearDown();
            if ($this->sqliteFile !== null) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->sqliteFile . $suffix);
                }
            }
        }
    }

    public function test_ranking_reads_every_chunk_from_one_snapshot(): void
    {
        $this->useReadCommitted();
        $admin = ServerGroup::forceCreate(['name' => 'admin']);
        $this->groupIds[] = $admin->id;
        Sanctum::actingAs($this->createUser(null, true));
        // 600 values without a group row: chunk one holds the first 500.
        $values = array_map(fn ($offset) => (string) (900000 + $offset), range(1, 600));
        $node = $this->createNode(['' . $admin->id, ...$values]);
        StatServer::create([
            'server_id' => $node->id, 'server_type' => 'vless',
            'u' => 100, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
        ]);
        // The only user outside the admin group moves from chunk two's group
        // to chunk one's after chunk one was read.
        $user = $this->createUser(900550);
        $this->moveAfterFirstChunk($user, 900010, 'MAX(CASE WHEN group_id IN');

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $ranked = array_column($this->getJson("/api/v2/{$securePath}/stat/getTrafficRank?type=node"
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60))->assertOk()->json('data'), 'id');
        $this->assertSame(900010, (int) User::whereKey($user->id)->value('group_id'));
        $this->assertSame([(string) $node->id], $ranked);
    }

    /**
     * Before: group A is named admin and the node's other value, B, has no
     * group row but has a user, so the node stays in the ranking. After: A is
     * renamed and that user moved to A, so it still stays. Reading the admin
     * names before and the groups and users after would drop it.
     */
    public function test_ranking_reads_admin_group_names_in_the_same_snapshot(): void
    {
        $this->useReadCommitted();
        $groupA = ServerGroup::forceCreate(['name' => 'admin']);
        $this->groupIds[] = $groupA->id;
        Sanctum::actingAs($this->createUser(null, true));
        $node = $this->createNode([(string) $groupA->id, '900777']);
        StatServer::create([
            'server_id' => $node->id, 'server_type' => 'vless',
            'u' => 100, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
        ]);
        $user = $this->createUser(900777);
        $changed = false;
        DB::listen(function (QueryExecuted $query) use (&$changed, $groupA, $user) {
            if ($changed || $query->connectionName !== DB::getDefaultConnection()
                || !preg_match('/from ["`]v2_server_group["`]$/', trim($query->sql))) {
                return;
            }
            $changed = true;
            DB::connection('chunks_other')->transaction(function ($other) use ($groupA, $user) {
                $other->table('v2_server_group')->where('id', $groupA->id)->update(['name' => 'VIP']);
                $other->table('v2_user')->where('id', $user->id)->update(['group_id' => $groupA->id]);
            });
        });

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $ranked = array_column($this->getJson("/api/v2/{$securePath}/stat/getTrafficRank?type=node"
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60))->assertOk()->json('data'), 'id');
        $this->assertTrue($changed);
        $this->assertSame([(string) $node->id], $ranked);
    }

    /**
     * Another connection renames both of a node's groups in one transaction
     * between the plain-ID query and the legacy value's own query.
     */
    public function test_node_list_reads_group_names_from_one_snapshot(): void
    {
        $this->useReadCommitted();
        $first = ServerGroup::forceCreate(['name' => 'one']);
        $second = ServerGroup::forceCreate(['name' => 'two']);
        array_push($this->groupIds, $first->id, $second->id);
        Sanctum::actingAs($this->createUser(null, true));
        // A plain ID and a zero-padded legacy value of the second group.
        $node = $this->createNode([(string) $first->id, '0' . $second->id]);
        $renamed = false;
        DB::listen(function (QueryExecuted $query) use (&$renamed, $first, $second) {
            if ($renamed || $query->connectionName !== DB::getDefaultConnection()
                || !preg_match('/from ["`]v2_server_group["`] where ["`]id["`] in \(\d/', $query->sql)) {
                return;
            }
            $renamed = true;
            DB::connection('chunks_other')->transaction(function ($other) use ($first, $second) {
                $other->table('v2_server_group')->where('id', $first->id)->update(['name' => 'one renamed']);
                $other->table('v2_server_group')->where('id', $second->id)->update(['name' => 'two renamed']);
            });
        });

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $listed = collect($this->getJson("/api/v2/{$securePath}/server/manage/getNodes")->assertOk()->json('data'))
            ->firstWhere('id', $node->id);
        $this->assertTrue($renamed);
        $this->assertSame(['one', 'two'], array_column($listed['groups'], 'name'));
    }

    public function test_ban_pushes_read_every_chunk_from_one_snapshot(): void
    {
        $this->useReadCommitted();
        $values = array_map(fn ($offset) => (string) (900000 + $offset), range(1, 600));
        $node = $this->createNode($values);
        $reporter = $this->createNode(['900550']);
        foreach ([$node, $reporter] as $server) {
            Cache::put("node_ws_alive:{$server->id}", true);
        }
        $user = $this->createUser(900550);
        $this->moveAfterFirstChunk($user, 900010, 'CASE WHEN group_id IN');
        $pushes = [];
        Redis::enableEvents();
        Redis::listen(function (CommandExecuted $command) use (&$pushes): void {
            if ($command->command === 'publish') {
                $pushes[] = json_decode($command->parameters[1], true, flags: JSON_THROW_ON_ERROR)['node_id'];
            }
        });

        app(DeviceBanService::class)->reportBans($reporter, [$user->id => (int) floor(microtime(true) * 1000) + 179000]);
        $this->assertSame(900010, (int) User::whereKey($user->id)->value('group_id'));
        // The snapshot still has the user in group 900550, which both nodes serve.
        $this->assertEqualsCanonicalizing([$node->id, $reporter->id], $pushes);
    }

    /** PostgreSQL's default; MySQL is set to it so each statement sees the latest commit. */
    private function useReadCommitted(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
    }

    private function moveAfterFirstChunk(User $user, int $group, string $marker): void
    {
        $moved = false;
        DB::listen(function (QueryExecuted $query) use (&$moved, $user, $group, $marker) {
            if ($moved || $query->connectionName !== DB::getDefaultConnection() || !str_contains($query->sql, $marker)) {
                return;
            }
            $moved = true;
            DB::connection('chunks_other')->table('v2_user')->where('id', $user->id)->update(['group_id' => $group]);
        });
    }

    private function createNode(array $groupIds): Server
    {
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'node', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => true,
        ]));
        Server::whereKey($node->id)->toBase()->update(['group_ids' => json_encode($groupIds)]);
        $this->serverIds[] = $node->id;
        return $node->fresh();
    }

    private function createUser(?int $group, bool $admin = false): User
    {
        $user = User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com', 'password' => '', 'uuid' => Helper::guid(true),
            'token' => Helper::guid(), 'is_admin' => $admin, 'group_id' => $group,
            'transfer_enable' => 1024, 'expired_at' => null, 'device_limit' => 1,
        ]));
        $this->userIds[] = $user->id;
        return $user;
    }
}
