<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Services\Plugin\HookManager;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminListTrafficTest extends TestCase
{
    use RefreshDatabase;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisPrefix = 'list_traffic_test:' . bin2hex(random_bytes(8)) . ':';
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

    private function endpoint(string $path): string
    {
        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $securePath . '/' . $path;
    }

    private function createUser(bool $admin = false): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com',
            'password' => '',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $admin,
        ]));
    }

    private function createServer(string $name): Server
    {
        return Server::withoutEvents(fn () => Server::create([
            'name' => $name, 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
        ]));
    }

    public function test_node_list_includes_only_todays_daily_traffic(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $node = $this->createServer('busy');
        $idle = $this->createServer('idle');
        $today = strtotime(date('Y-m-d'));
        foreach ([
            // A type change during the day leaves two rows for the same node.
            ['vless', $today, 'd', 100, 200],
            ['vmess', $today, 'd', 10, 20],
            ['vless', $today - 86400, 'd', 5000, 5000],
            // A monthly row with today's timestamp (and its own type, to stay
            // clear of the unique key) must not count as daily traffic.
            ['trojan', $today, 'm', 7000, 7000],
        ] as [$type, $recordAt, $recordType, $upload, $download]) {
            StatServer::create([
                'server_id' => $node->id, 'server_type' => $type, 'record_at' => $recordAt,
                'record_type' => $recordType, 'u' => $upload, 'd' => $download,
            ]);
        }

        $nodes = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertSame(330, $nodes[$node->id]['today_traffic']);
        $this->assertSame(0, $nodes[$idle->id]['today_traffic']);
    }

    public function test_user_list_counts_nodes_with_recorded_cycle_traffic(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $user = $this->createUser();
        $idle = $this->createUser();
        foreach ([[1, 'kept'], [2, 'other'], [999, 'deleted node']] as [$serverId, $name]) {
            UserServerTraffic::create([
                'user_id' => $user->id, 'server_id' => $serverId, 'server_name' => $name,
                'u' => 1, 'd' => 1, 'billed_u' => 1, 'billed_d' => 1,
            ]);
        }

        $users = collect($this->getJson($this->endpoint('user/fetch') . '?pageSize=50')->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertSame(3, $users[$user->id]['used_node_count']);
        $this->assertSame(0, $users[$idle->id]['used_node_count']);
    }

    public function test_user_list_keeps_usage_fields_a_plugin_defines(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $user = $this->createUser();
        User::whereKey($user->id)->toBase()->update(['u' => 100, 'd' => 200]);
        HookManager::registerFilter('admin.user.fetch.query',
            fn ($query) => $query->select('v2_user.*')->selectRaw('(u + d) * 2 as total_used'));

        $row = collect($this->getJson($this->endpoint('user/fetch') . '?pageSize=50')->assertOk()->json('data'))
            ->firstWhere('id', $user->id);
        $this->assertSame([100, 200, 600], [$row['u'], $row['d'], $row['total_used']]);
    }

    public function test_user_list_keeps_node_count_when_a_plugin_rebuilds_the_select(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $user = $this->createUser();
        UserServerTraffic::create([
            'user_id' => $user->id, 'server_id' => 1, 'server_name' => 'node',
            'u' => 1, 'd' => 1, 'billed_u' => 1, 'billed_d' => 1,
        ]);
        HookManager::registerFilter('admin.user.fetch.query',
            fn ($query) => $query->select('v2_user.*')->selectRaw('(u + d) as total_used'));

        $users = collect($this->getJson($this->endpoint('user/fetch') . '?pageSize=50')->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertSame(1, $users[$user->id]['used_node_count']);
    }

    public function test_user_list_keeps_node_count_when_a_plugin_aliases_the_user_table(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $user = $this->createUser();
        UserServerTraffic::create([
            'user_id' => $user->id, 'server_id' => 1, 'server_name' => 'node',
            'u' => 1, 'd' => 1, 'billed_u' => 1, 'billed_d' => 1,
        ]);
        HookManager::registerFilter('admin.user.fetch.query',
            fn ($query) => $query->from('v2_user as listed_user')->select('listed_user.*')
                ->selectRaw('(listed_user.u + listed_user.d) as total_used'));

        $users = collect($this->getJson($this->endpoint('user/fetch') . '?pageSize=50')->assertOk()->json('data'))
            ->keyBy('id');
        $this->assertSame(1, $users[$user->id]['used_node_count']);
    }

    public function test_today_traffic_sort_works_with_a_plugin_aliased_user_source(): void
    {
        $this->travelTo(Date::parse('2026-05-01 12:00:00'));
        $active = $this->createUser();
        $idle = $this->createUser();
        Sanctum::actingAs($this->createUser(true));
        UserServerTraffic::create([
            'user_id' => $active->id, 'server_id' => 1, 'server_name' => 'node',
            'daily_record_at' => Date::today()->timestamp, 'daily_billed_u' => 123,
        ]);
        $request = [
            'pageSize' => 1,
            'sort' => [['id' => 'today_traffic', 'desc' => true]],
        ];
        $endpoint = $this->endpoint('user/fetch');

        HookManager::registerFilter('admin.user.fetch.query',
            fn ($query) => $query->from('v2_user as listed_user')->select('listed_user.*')
                ->whereIn('listed_user.id', [$active->id, $idle->id])
                ->selectRaw('(listed_user.u + listed_user.d) * 2 as total_used'));
        $this->postJson($endpoint, $request)->assertOk()
            ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('data.0.today_traffic', 123);

        HookManager::registerFilter('admin.user.fetch.query',
            fn ($query) => $query->fromSub(User::query()->toBase(), 'listed_user')->select('listed_user.*')
                ->leftJoin('v2_plan as listed_plan', 'listed_plan.id', '=', 'listed_user.plan_id')
                ->selectRaw('(listed_user.u + listed_user.d) as total_used'));
        $this->postJson($endpoint, ['pageSize' => 1])->assertOk()
            ->assertJsonPath('data.0.id', $idle->id);
        $this->postJson($endpoint, $request)->assertOk()
            ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('data.0.today_traffic', 123);

        HookManager::registerFilter('admin.user.fetch.query',
            fn ($query) => $query->fromRaw('(select * from v2_user) listed_user'));
        $this->postJson($endpoint, $request)->assertOk()
            ->assertJsonPath('data.0.id', $active->id)->assertJsonPath('data.0.today_traffic', 123);
    }

    public function test_node_list_shows_the_groups_full_sync_serves(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $group = ServerGroup::forceCreate(['name' => 'VIP']);
        $id = (string) $group->id;
        $expected = [];
        foreach ([
            'list' => [json_encode([$id, [$id], null]), [$id]],
            'scalar' => [json_encode($id), [$id]],
            'intScalar' => [json_encode($group->id), []],
            'object' => [json_encode(['first' => $id]), [$id]],
        ] as $name => [$groupIds, $groups]) {
            $node = $this->createServer($name);
            Server::whereKey($node->id)->toBase()->update(['group_ids' => $groupIds]);
            $expected[$node->id] = $groups;
        }

        $nodes = collect($this->getJson($this->endpoint('server/manage/getNodes'))->assertOk()->json('data'))
            ->keyBy('id');
        foreach ($expected as $nodeId => $groups) {
            // The admin form and group filter call array methods on group_ids.
            $this->assertSame($groups, $nodes[$nodeId]['group_ids']);
            $this->assertSame(array_map('intval', $groups), array_column($nodes[$nodeId]['groups'], 'id'));
        }
    }
}
