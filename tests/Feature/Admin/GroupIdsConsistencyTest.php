<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\User;
use App\Services\Plugin\HookManager;
use App\Services\ServerService;
use App\Utils\Helper;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The admin node list and the traffic ranking must describe the groups that
 * full user sync actually serves a node, on whichever database runs the test.
 */
class GroupIdsConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        admin_setting(['server_token' => 'test-node-token']);
        $this->redisPrefix = 'group_ids_test:' . bin2hex(random_bytes(8)) . ':';
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
        } finally {
            parent::tearDown();
        }
    }

    public static function shapes(): array
    {
        return array_map(fn ($json) => [$json], [
            'list' => '["1","2"]', 'admin list' => '["1"]', 'integer list' => '[1]', 'boolean' => '[true]',
            'null with admin' => '[null,"1"]', 'junk with admin' => '["1","abc"]', 'zero padded' => '["01"]',
            'groupless users' => '["1","3"]', 'groupless padded' => '["1","03"]', 'unused id' => '["1","99"]',
            'object' => '{"a":"1"}', 'rejected object' => '{"a":["1","2"]}', 'rejected object with admin' => '{"a":"1","b":["1","2"]}',
            'single nested' => '[["1"]]', 'multi nested' => '[["1","2"]]', 'mixed nested' => '["1",["2","3"]]',
            'string scalar' => '"1"', 'padded scalar' => '"01"', 'integer scalar' => '1', 'empty' => '[]',
            // whereIn binds fewer values than elements: SQLite served nobody, MySQL failed.
            'empty nested with admin' => '["1",[[]]]', 'empty nested pair' => '["1",[[]],"2"]',
            // whereIn binds more values than elements: every database failed.
            'deep nested' => '[[["1","2"]]]',
            // The request trims strings and turns blank ones into null.
            'padded groupless' => '["1"," 3"]', 'padded unused' => '[" 99"]', 'blank' => '["1","  "]',
        ]);
    }

    #[DataProvider('shapes')]
    public function test_node_list_and_ranking_follow_full_sync(string $stored): void
    {
        ServerGroup::forceCreate(['id' => 1, 'name' => 'admin']);
        ServerGroup::forceCreate(['id' => 2, 'name' => 'VIP']);
        Sanctum::actingAs($this->createUser(1, true));
        $this->createUser(2);
        // Plans accept any group_id, so users can be in a group with no row.
        $this->createUser(3);
        $node = Server::withoutEvents(fn () => Server::create([
            'name' => 'node', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => true,
        ]));
        Server::whereKey($node->id)->toBase()->update(['group_ids' => $stored]);
        StatServer::create([
            'server_id' => $node->id, 'server_type' => 'vless',
            'u' => 100, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
        ]);

        $served = User::whereIn('id', $this->fullSync($node) ?? [])->distinct()->orderBy('group_id')
            ->pluck('group_id')->map(fn ($id) => (int) $id)->all();

        $listed = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))
            ->firstWhere('id', $node->id);
        $this->assertSame(array_values(array_intersect($served, [1, 2])),
            collect($listed['groups'])->pluck('id')->sort()->values()->all(), 'groups');
        $this->assertContainsOnly('string', $listed['group_ids']);

        $ranked = array_column($this->getJson($this->endpoint('stat/getTrafficRank?type=node'
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60)))->assertOk()->json('data'), 'id');
        $adminOnly = $served !== [] && array_diff($served, [1]) === [];
        $this->assertSame(!$adminOnly, in_array((string) $node->id, $ranked, true), 'ranking');
    }

    #[DataProvider('shapes')]
    public function test_saving_without_group_ids_keeps_the_stored_value_and_sent_group_ids_are_written(string $stored): void
    {
        ServerGroup::forceCreate(['id' => 1, 'name' => 'admin']);
        ServerGroup::forceCreate(['id' => 2, 'name' => 'VIP']);
        Sanctum::actingAs($this->createUser(1, true));
        $member = $this->createUser(1);
        $node = $this->createNode();
        Server::whereKey($node->id)->toBase()->update(['group_ids' => $stored]);
        $raw = fn () => Server::whereKey($node->id)->toBase()->value('group_ids');
        $before = $raw();
        $visible = fn () => in_array($node->id, array_column(ServerService::getAvailableServers($member->fresh()), 'id'), true);
        $wasVisible = $visible();
        $synced = fn () => $this->fullSync($node);
        $syncedBefore = $synced();

        // The admin dialog leaves group_ids out when the admin did not change them.
        $payload = $this->savePayload($node, 'renamed', []);
        unset($payload['group_ids']);
        $this->postJson($this->endpoint('server/manage/save'), $payload)->assertOk()->assertJsonPath('data', true);
        $this->assertSame('renamed', Server::find($node->id)->name);
        $this->assertSame($before, $raw());
        $this->assertSame($wasVisible, $visible(), 'subscription');
        $this->assertSame($syncedBefore, $synced(), 'full sync');

        // Sent group_ids are written, even when they equal the listed ones
        // (for example ["3"] chosen for a node that stored ["03"]).
        $listed = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))
            ->firstWhere('id', $node->id)['group_ids'];
        foreach ([$listed, ['2']] as $chosen) {
            $this->postJson($this->endpoint('server/manage/save'), $this->savePayload($node, 'renamed', $chosen))
                ->assertOk()->assertJsonPath('data', true);
            // The request trims strings and turns blank ones into null.
            $this->assertSame(array_map(fn ($id) => trim($id) === '' ? null : trim($id), $chosen),
                Server::find($node->id)->group_ids);
        }
    }

    /**
     * On SQLite a nested empty list ran full sync with shifted bindings: it
     * served nobody, but the server.users.get hook still ran. It still does.
     * Elsewhere the row failed and now recovers group 1, so the hook runs too.
     */
    public function test_full_sync_runs_the_user_hook_for_short_binding_rows(): void
    {
        $this->createUser(1);
        HookManager::registerFilter('server.users.get', fn ($users) => $users->push((object) [
            'id' => 999999, 'uuid' => Helper::guid(true), 'speed_limit' => null, 'device_limit' => null,
        ]));
        $node = $this->createNode();
        Server::whereKey($node->id)->toBase()->update(['group_ids' => '["1",[[]]]']);
        $this->assertContains(999999, $this->fullSync($node));

        // Empty values (empty() in PHP) never reached the query or the hook.
        foreach (['[]', '"0"', '0', '""'] as $stored) {
            Server::whereKey($node->id)->toBase()->update(['group_ids' => $stored]);
            $this->assertSame([], $this->fullSync($node), $stored);
        }
    }

    /**
     * PostgreSQL rejects a value such as "abc" for an integer column; SQLite
     * and MySQL compare it. Simulate that rejection for queries comparing
     * "abc" with a group ID, as PostgreSQL 15 reports it (SQLSTATE 22P02).
     */
    public function test_a_value_the_database_rejects_affects_only_its_own_node(): void
    {
        ServerGroup::forceCreate(['id' => 1, 'name' => 'admin']);
        Sanctum::actingAs($this->createUser(1, true));
        DB::beforeExecuting(function (string $sql, array $bindings, $connection) {
            if (in_array('abc', $bindings, true)
                && (str_contains($sql, 'group_id') || str_contains($sql, 'v2_server_group'))) {
                throw new QueryException($connection->getName(), $sql, $bindings,
                    new class ('invalid input syntax for type integer: "abc"') extends \PDOException {
                        protected $code = '22P02';
                    });
            }
        });
        $nodes = [];
        foreach (['admin' => '["1"]', 'rejected' => '["1","abc"]', 'idle' => '["abc"]'] as $name => $stored) {
            $nodes[$name] = $this->createNode();
            Server::whereKey($nodes[$name]->id)->toBase()->update(['group_ids' => $stored]);
        }
        foreach (['admin' => 200, 'rejected' => 100] as $name => $traffic) {
            StatServer::create([
                'server_id' => $nodes[$name]->id, 'server_type' => 'vless',
                'u' => $traffic, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
            ]);
        }

        // Inside a transaction (as in this test) the failure must not abort it.
        $listed = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame(['1', 'abc'], $listed[$nodes['rejected']->id]['group_ids']);
        // Full sync fails for it on such a database, so it serves no group.
        $this->assertSame([], $listed[$nodes['rejected']->id]['groups']);
        $this->assertSame(['abc'], $listed[$nodes['idle']->id]['group_ids']);

        // Full sync fails for the rejected node, so it serves no one and
        // stays in the ranking; the admin-only node is still left out.
        $ranked = array_column($this->getJson($this->endpoint('stat/getTrafficRank?type=node'
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60)))->assertOk()->json('data'), 'id');
        $this->assertSame([(string) $nodes['rejected']->id], $ranked);
    }

    public function test_ranking_reads_the_user_table_once_for_groups_without_a_row(): void
    {
        ServerGroup::forceCreate(['id' => 1, 'name' => 'admin']);
        Sanctum::actingAs($this->createUser(1, true));
        $this->createUser(91);
        $this->createUser(91);
        $ranked = [];
        foreach (['["1","90"]', '["1","91"]', '["1","92"]', '["1","93"]', '["94"]'] as $index => $stored) {
            $node = $this->createNode();
            Server::whereKey($node->id)->toBase()->update(['group_ids' => $stored]);
            // ["1","93"] has no traffic in the period. ["94"] serves no admin
            // group, so it is never admin-only and needs no user check.
            if ($index !== 3) {
                StatServer::create([
                    'server_id' => $node->id, 'server_type' => 'vless',
                    'u' => 100 * ($index + 1), 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
                ]);
                $ranked[$stored] = (string) $node->id;
            }
        }
        $userQueries = [];
        DB::listen(function ($query) use (&$userQueries) {
            if ((str_contains($query->sql, '"v2_user"') || str_contains($query->sql, '`v2_user`'))
                && str_contains($query->sql, 'group_id')) {
                $userQueries[] = $query->bindings;
            }
        });

        $result = array_column($this->getJson($this->endpoint('stat/getTrafficRank?type=node'
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60)))->assertOk()->json('data'), 'id');
        // Group 91 still has users; 90 and 92 have none.
        $this->assertSame([$ranked['["94"]'], $ranked['["1","91"]']], $result);
        // One statement for 90, 91 and 92; not 93 (no traffic) or 94.
        $this->assertCount(1, $userQueries);
        $this->assertEqualsCanonicalizing(['90', '91', '92'], $userQueries[0]);
    }

    public function test_ranking_compares_more_group_values_than_a_statement_has_columns(): void
    {
        ServerGroup::forceCreate(['id' => 1, 'name' => 'admin']);
        Sanctum::actingAs($this->createUser(1, true));
        // 4,200 values without a group row: more result columns than SQLite
        // (2000) or PostgreSQL (1664) allow in one statement.
        // Users remain only in group 4100, so only the first node serves them.
        $this->createUser(4100);
        $nodes = [];
        foreach ([range(2, 4201), range(5000, 9199)] as $index => $values) {
            $nodes[$index] = $this->createNode();
            Server::whereKey($nodes[$index]->id)->toBase()
                ->update(['group_ids' => json_encode(['1', ...array_map('strval', $values)])]);
            StatServer::create([
                'server_id' => $nodes[$index]->id, 'server_type' => 'vless',
                'u' => 100, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
            ]);
        }

        $ranked = array_column($this->getJson($this->endpoint('stat/getTrafficRank?type=node'
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60)))->assertOk()->json('data'), 'id');
        $this->assertSame([(string) $nodes[0]->id], $ranked);
    }

    /**
     * The integer 0 and a JSON number too large for a float (INF) must not
     * share a cache entry: INF matches no group the way full sync compares it.
     */
    public function test_a_float_overflow_and_group_zero_keep_their_own_matches(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('MySQL JSON columns reject 1e400.');
        }
        ServerGroup::forceCreate(['id' => 0, 'name' => 'admin']);
        Sanctum::actingAs($this->createUser(1, true));
        $this->createUser(0);
        $nodes = [];
        foreach (['zero' => '[0]', 'overflow' => '[1e400]'] as $name => $stored) {
            $nodes[$name] = $this->createNode();
            Server::whereKey($nodes[$name]->id)->toBase()->update(['group_ids' => $stored]);
            StatServer::create([
                'server_id' => $nodes[$name]->id, 'server_type' => 'vless',
                'u' => 100, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
            ]);
        }
        // Full sync gives the overflow node no one (SQLite matches no group,
        // PostgreSQL rejects the value).
        $this->assertSame([], $this->fullSync($nodes['overflow']) ?? []);

        $listed = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame([['name' => 'admin', 'id' => 0]], $listed[$nodes['zero']->id]['groups']);
        $this->assertSame([], $listed[$nodes['overflow']->id]['groups']);
        // Only the zero node serves just the admin group.
        $ranked = array_column($this->getJson($this->endpoint('stat/getTrafficRank?type=node'
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60)))->assertOk()->json('data'), 'id');
        $this->assertSame([(string) $nodes['overflow']->id], $ranked);
    }

    public function test_node_list_converts_an_embedded_parent_like_the_parent_itself(): void
    {
        ServerGroup::forceCreate(['id' => 13, 'name' => 'thirteen']);
        Sanctum::actingAs($this->createUser(1, true));
        $legacy = ['["013"]'];
        if (!in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // A JSON number too large for a float decodes to INF, which
            // JsonResponse cannot encode. MySQL JSON columns reject it.
            $legacy[] = '[1e400]';
        }
        foreach ($legacy as $stored) {
            $parent = $this->createNode();
            Server::whereKey($parent->id)->toBase()->update(['group_ids' => $stored]);
            $child = $this->createNode();
            Server::whereKey($child->id)->toBase()->update(['parent_id' => $parent->id]);

            $listed = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))
                ->keyBy('id');
            $this->assertSame($listed[$parent->id]['group_ids'], $listed[$child->id]['parent']['group_ids'], $stored);
            if ($stored === '["013"]') {
                $this->assertSame(['13'], $listed[$child->id]['parent']['group_ids']);
            }
        }
    }

    public function test_node_list_keeps_group_zero(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // Otherwise MySQL assigns the next auto-increment ID to an explicit 0.
            DB::statement("SET SESSION sql_mode = CONCAT(@@sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        }
        ServerGroup::forceCreate(['id' => 0, 'name' => 'zero']);
        Sanctum::actingAs($this->createUser(1, true));
        $node = $this->createNode();
        Server::whereKey($node->id)->toBase()->update(['group_ids' => '["0"]']);

        $listed = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))
            ->firstWhere('id', $node->id);
        $this->assertSame(['0'], $listed['group_ids']);
        $this->assertSame([['name' => 'zero', 'id' => 0]], $listed['groups']);
    }

    public function test_node_list_reads_plain_group_ids_in_one_query(): void
    {
        Sanctum::actingAs($this->createUser(1, true));
        $plain = [];
        foreach (range(1, 24) as $id) {
            ServerGroup::forceCreate(['id' => $id, 'name' => "group {$id}"]);
            $node = $this->createNode();
            Server::whereKey($node->id)->toBase()->update(['group_ids' => json_encode([(string) $id])]);
            $plain[] = $node->id;
        }
        // Legacy values are compared by the database one by one.
        foreach (['["01"]', '[true]'] as $stored) {
            $legacy = $this->createNode();
            Server::whereKey($legacy->id)->toBase()->update(['group_ids' => $stored]);
        }
        $groupQueries = 0;
        DB::listen(function ($query) use (&$groupQueries) {
            if (preg_match('/from ["`]v2_server_group["`]/', $query->sql)) {
                $groupQueries++;
            }
        });
        $savepoints = 0;
        Event::listen(TransactionBeginning::class, function () use (&$savepoints) {
            $savepoints++;
        });

        $nodes = $this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data');
        // One query for the 24 plain IDs, then "01" and true on their own,
        // each in a savepoint because this test runs inside a transaction.
        $this->assertSame(3, $groupQueries);
        $this->assertSame(2, $savepoints);
        foreach (collect($nodes)->whereIn('id', $plain) as $node) {
            $this->assertSame([['name' => "group {$node['group_ids'][0]}", 'id' => (int) $node['group_ids'][0]]],
                $node['groups']);
        }
    }

    /**
     * The user IDs full sync gives a node, or null when the database rejects
     * a stored value (a data exception, SQLSTATE class 22, such as PostgreSQL
     * rejecting "abc" for an integer). Any other failure fails the test. A
     * savepoint keeps the test's transaction usable after a rejection.
     */
    private function fullSync(Server $node): ?array
    {
        DB::beginTransaction();
        $this->withoutExceptionHandling();
        try {
            $response = $this->getJson('/api/v1/server/UniProxy/user?' . http_build_query([
                'token' => 'test-node-token', 'node_id' => $node->id, 'node_type' => 'vless',
            ]));
        } catch (QueryException $error) {
            DB::rollBack();
            if (!str_starts_with((string) $error->getCode(), '22')) {
                throw $error;
            }
            return null;
        } finally {
            $this->withExceptionHandling();
        }
        DB::commit();
        return array_column($response->assertOk()->json('users'), 'id');
    }

    private function createNode(): Server
    {
        return Server::withoutEvents(fn () => Server::create([
            'name' => 'node', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => true,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
        ]));
    }

    private function savePayload(Server $node, string $name, array $groupIds): array
    {
        return [
            'id' => $node->id, 'type' => 'vless', 'name' => $name, 'group_ids' => $groupIds,
            'host' => '127.0.0.1', 'port' => 443, 'server_port' => 443, 'rate' => 1, 'show' => 1,
            'protocol_settings' => ['tls' => 0, 'network' => 'tcp'],
        ];
    }

    private function endpoint(string $path): string
    {
        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $securePath . '/' . $path;
    }

    private function createUser(int $group, bool $admin = false): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com', 'password' => '', 'uuid' => Helper::guid(true),
            'token' => Helper::guid(), 'is_admin' => $admin, 'group_id' => $group,
            'transfer_enable' => 1024, 'expired_at' => null,
        ]));
    }
}
