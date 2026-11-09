<?php

namespace Tests\Feature\Server;

use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceBanService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class DeviceBanTest extends TestCase
{
    use RefreshDatabase;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        admin_setting(['server_token' => 'test-node-token']);
        $this->redisPrefix = 'device_ban_test:' . bin2hex(random_bytes(8)) . ':';
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

    public function test_rejected_connection_starts_one_shared_ban_and_retry_does_not_extend_it(): void
    {
        $user = $this->makeUser();
        $a = $this->makeServer(1);
        $b = $this->makeServer(1);
        $unrelated = $this->makeServer(2);
        foreach ([$a, $b, $unrelated] as $node) {
            Cache::put("node_ws_alive:{$node->id}", true);
        }
        $pushes = [];
        Redis::enableEvents();
        Redis::listen(function (CommandExecuted $command) use (&$pushes): void {
            if ($command->command === 'publish') {
                $pushes[] = json_decode($command->parameters[1], true, flags: JSON_THROW_ON_ERROR);
            }
        });
        $now = (int) floor(microtime(true) * 1000);
        $deadline = $now + 179000;

        $this->postJson('/api/v2/server/device-bans', $this->auth($unrelated) + [
            'device_bans' => [$user->id => $deadline],
        ])->assertOk()->assertJsonPath('device_bans', []);
        $this->postJson('/api/v2/server/device-bans', $this->auth($a) + [
            'device_bans' => [$user->id => $now - 1000],
        ])->assertOk()->assertJsonPath('device_bans', []);

        $this->postJson('/api/v2/server/device-bans', $this->auth($a) + [
            'device_bans' => [$user->id => $deadline],
        ])->assertOk()->assertJsonPath("device_bans.{$user->id}", $deadline);
        $this->postJson('/api/v2/server/device-bans', $this->auth($b) + [
            'device_bans' => [$user->id => $deadline + 10000],
        ])->assertOk()->assertJsonPath("device_bans.{$user->id}", $deadline);
        $this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($b)))
            ->assertOk()->assertJsonPath("device_bans.{$user->id}", $deadline);
        $snapshot = app(DeviceBanService::class)->getDeviceSnapshot([$user->id]);
        $this->assertSame([], $snapshot['users']);
        $this->assertSame([$user->id => $deadline], (array) $snapshot['device_bans']);

        $this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($unrelated)))
            ->assertOk()->assertJsonPath('device_bans', []);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($pushes, 'node_id'));
        foreach ($pushes as $push) {
            $this->assertSame('sync.devices', $push['event']);
            $this->assertSame([$user->id => $deadline], $push['data']['device_bans']);
        }
        $this->assertSame(0, (int) $user->fresh()->banned);

        // A delayed report generated during the expired window cannot restart it.
        Redis::zadd('device:bans', $now - 1000, $user->id);
        $this->postJson('/api/v2/server/device-bans', $this->auth($b) + [
            'device_bans' => [$user->id => $now + 178000],
        ])->assertOk()->assertJsonPath('device_bans', []);
    }

    public function test_unchanged_sequenced_and_legacy_reports_skip_extra_device_checks(): void
    {
        $user = $this->makeUser();
        $a = $this->makeServer(1);
        $b = $this->makeServer(1);
        $key = "user_devices:{$user->id}";
        $ip = '192.0.2.1';
        $reads = 0;
        Redis::enableEvents();
        Redis::listen(function (CommandExecuted $command) use (&$reads): void {
            if (strtolower($command->command) === 'zrangebyscore') {
                ++$reads;
            }
        });
        $legacyQuery = http_build_query($this->auth($b) + ['node_type' => 'vmess']);
        $report = function (int $sequence) use ($a, $user, $ip): void {
            $this->postJson('/api/v2/server/report', $this->auth($a) + [
                'alive' => [$user->id => [$ip]],
                'device_sequence' => $sequence,
                'device_full_snapshot' => true,
            ])->assertOk();
        };
        $report(1);
        $this->postJson('/api/v1/server/UniProxy/alive?' . $legacyQuery, [
            $user->id => [$ip],
        ])->assertOk();
        $this->assertSame(2, $reads);

        $previous = time() - 60;
        Redis::hset($key, "{$a->id}:{$ip}", $previous);
        Redis::hset($key, "{$b->id}:{$ip}", $previous);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report(2);
        $this->postJson('/api/v1/server/UniProxy/alive?' . $legacyQuery, [
            $user->id => ["{$ip}:443", $ip],
        ])->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertSame(2, $reads);
        $this->assertSame([], array_values(array_filter($queries, static fn ($sql) => str_contains($sql, 'v2_user'))));
        $this->assertGreaterThan($previous, (int) Redis::hget($key, "{$a->id}:{$ip}"));
        $this->assertGreaterThan($previous, (int) Redis::hget($key, "{$b->id}:{$ip}"));

        Redis::del("device:online_touch_due:{$user->id}");
        $report(3);
        $this->assertSame(3, $reads);
        $this->postJson('/api/v1/server/UniProxy/alive?' . $legacyQuery, [
            $user->id => ['198.51.100.2'],
        ])->assertOk();
        $this->assertSame(4, $reads);
        $this->getJson('/api/v1/server/UniProxy/user?' . $legacyQuery)
            ->assertOk()->assertJsonPath('users.0.id', $user->id);
        $this->assertIsInt($this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($a)))
            ->assertOk()->json("device_bans.{$user->id}"));
    }

    public function test_combined_reports_trigger_ban_for_connections_already_on_two_nodes(): void
    {
        $user = $this->makeUser();
        $a = $this->makeServer(1);
        $b = $this->makeServer(1);
        $report = function (Server $node, array $ips, int $sequence) use ($user): void {
            $this->postJson('/api/v2/server/report', $this->auth($node) + [
                'alive' => [$user->id => $ips],
                'device_sequence' => $sequence,
                'device_full_snapshot' => true,
            ])->assertOk();
        };
        $report($a, ['192.0.2.1'], 2);
        $report($b, ['192.0.2.1'], 2);
        $report($b, ['198.51.100.2'], 1);
        $this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($a)))
            ->assertOk()->assertJsonPath('device_bans', []);

        $before = (int) floor(microtime(true) * 1000);
        $report($b, ['198.51.100.2'], 3);
        $after = (int) floor(microtime(true) * 1000);
        $deadline = $this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($a)))
            ->assertOk()->json("device_bans.{$user->id}");
        $this->assertIsInt($deadline);
        $this->assertGreaterThanOrEqual($before + 180000, $deadline);
        $this->assertLessThanOrEqual($after + 180000, $deadline);
        $snapshot = app(DeviceBanService::class)->getDeviceSnapshot([$user->id]);
        $this->assertEqualsCanonicalizing(['192.0.2.1', '198.51.100.2'], $snapshot['users'][$user->id]);
        $this->assertSame([$user->id => $deadline], (array) $snapshot['device_bans']);
        $legacyIps = $this->getJson('/api/v2/server/devices?' . http_build_query($this->auth($b)))
            ->assertOk()->json("users.{$user->id}");
        $this->assertEqualsCanonicalizing(['192.0.2.1', '198.51.100.2'], $legacyIps);
        $report($a, [], 3);
        $report($b, [], 4);
        $this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($b)))
            ->assertOk()->assertJsonPath("device_bans.{$user->id}", $deadline);

        // A node's older IP report can outlive the three-minute window.
        Redis::zadd('device:bans', $before - 1000, $user->id);
        Redis::hset("user_devices:{$user->id}", "{$a->id}:192.0.2.1", time() - 120);
        $report($b, ['198.51.100.2'], 5);
        $this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($b)))
            ->assertOk()->assertJsonPath('device_bans', []);
        $this->getJson('/api/v2/server/devices?' . http_build_query($this->auth($b)))
            ->assertOk()->assertJsonPath("users.{$user->id}", ['198.51.100.2']);
    }

    public function test_failed_device_check_retries_on_the_next_unchanged_report(): void
    {
        $user = $this->makeUser();
        $a = $this->makeServer(1);
        $b = $this->makeServer(1);
        $report = fn (Server $node, string $ip, int $sequence) => $this->postJson(
            '/api/v2/server/report',
            $this->auth($node) + [
                'alive' => [$user->id => [$ip]],
                'device_sequence' => $sequence,
                'device_full_snapshot' => true,
            ]
        );
        $report($a, '192.0.2.1', 1)->assertOk();
        Bus::fake();
        $fail = true;
        DB::listen(function ($query) use (&$fail): void {
            if ($fail && str_contains($query->sql, 'v2_user')) {
                $fail = false;
                throw new \RuntimeException('Simulated device limit query failure');
            }
        });

        $this->postJson('/api/v2/server/report', $this->auth($b) + [
            'alive' => [$user->id => ['198.51.100.2']],
            'traffic' => [$user->id => [15, 25]],
        ])->assertOk()->assertJsonPath('data', true);
        $report($b, '198.51.100.2', 2)->assertOk();
        Bus::assertDispatchedTimes(TrafficFetchJob::class, 1);
        $this->assertIsInt($this->getJson('/api/v2/server/device-bans?' . http_build_query($this->auth($a)))
            ->assertOk()->json("device_bans.{$user->id}"));
    }

    private function auth(Server $node): array
    {
        return ['token' => 'test-node-token', 'node_id' => $node->id];
    }

    private function makeUser(): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => 'device-ban@example.com',
            'password' => '',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'group_id' => 1,
            'transfer_enable' => 1024,
            'expired_at' => null,
            'device_limit' => 1,
        ]));
    }

    private function makeServer(int $group): Server
    {
        return Server::create([
            'name' => 'test-node',
            'type' => Server::TYPE_VMESS,
            'host' => '127.0.0.1',
            'port' => 443,
            'server_port' => 443,
            'rate' => '1',
            'group_ids' => [$group],
        ]);
    }
}
