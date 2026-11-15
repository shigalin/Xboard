<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class NodeOnlineUsersTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin = false, array $attributes = []): User
    {
        return User::withoutEvents(fn () => User::create(array_merge([
            'email' => Helper::guid() . '@example.com', 'password' => '',
            'uuid' => Helper::guid(true), 'token' => Helper::guid(), 'is_admin' => $admin,
        ], $attributes)));
    }

    private function node(array $attributes = []): Server
    {
        return Server::withoutEvents(fn () => Server::create(array_merge([
            'name' => 'Online users test', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
        ], $attributes)));
    }

    private function endpoint(): string
    {
        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $path . '/server/manage/getOnlineUsers';
    }

    /** Exercise the real state reader against node-scoped Redis responses. */
    private function expectDeviceRows(int $nodeId, array $rows): void
    {
        Redis::shouldReceive('sscan')->once()
            ->with('device:node_index:' . $nodeId, null, ['count' => 200])
            ->andReturn(['0', array_map('strval', array_keys($rows))]);
        if ($rows !== []) {
            Redis::shouldReceive('pipeline')->once()->andReturnUsing(function ($callback) use ($rows) {
                $pipe = Mockery::mock();
                foreach (array_keys($rows) as $userId) {
                    $pipe->shouldReceive('hgetall')->once()->with('user_devices:' . $userId);
                }
                $callback($pipe);
                return array_values($rows);
            });
        }
    }

    public function test_access_is_strictly_admin_only_and_node_id_is_validated(): void
    {
        $url = $this->endpoint();
        $this->getJson($url . '?id=1')->assertForbidden();
        Sanctum::actingAs($this->user());
        $this->getJson($url . '?id=1')->assertForbidden();
        Sanctum::actingAs($this->user(false, ['is_staff' => true]));
        $this->getJson($url . '?id=1')->assertForbidden();
        Sanctum::actingAs($this->user(true));
        foreach (['', '?id=0', '?id=-1', '?id=1.5', '?id=abc', '?id[]=1'] as $query) {
            $this->getJson($url . $query)->assertUnprocessable();
        }
        $this->getJson($url . '?id=999999')->assertStatus(400);
    }

    public function test_returns_only_fresh_node_users_with_minimal_fields_and_keeps_reported_count_separate(): void
    {
        Sanctum::actingAs($this->user(true));
        $node = $this->node();
        $online = $this->user();
        $expired = $this->user();
        $otherNodeUser = $this->user();
        $this->user(); // A user absent from this node's index must not be returned.
        $now = time();
        $this->expectDeviceRows($node->id, [
            $online->id => [
                $node->id . ':192.0.2.1' => $now - 10,
                $node->id . ':2001:db8::1' => $now - 20,
                $node->id . ':192.0.2.99' => $now - 301,
                ($node->id + 10) . ':198.51.100.1' => $now,
            ],
            $expired->id => [$node->id . ':192.0.2.2' => $now - 301],
            $otherNodeUser->id => [($node->id + 10) . ':198.51.100.2' => $now],
            999999 => [$node->id . ':192.0.2.3' => $now], // Deleted user.
            999998 => [], // Expired hash with a lingering index member.
        ]);
        Cache::put(CacheKey::get('SERVER_VLESS_ONLINE_USER', $node->id), 9, 3600);

        $this->getJson($this->endpoint() . '?id=' . $node->id)->assertOk()
            ->assertJsonPath('data', [
                'list' => [[
                    'id' => $online->id,
                    'email' => $online->email,
                    'online_ip_count' => 2,
                    'last_seen_at' => $now - 10,
                ]],
                'total' => 1,
                'reported_online' => 9,
                'source_node_id' => $node->id,
            ]);
    }

    public function test_child_node_uses_its_immediate_parent_for_devices_and_reported_count(): void
    {
        Sanctum::actingAs($this->user(true));
        $parent = $this->node();
        $child = $this->node(['parent_id' => $parent->id]);
        $online = $this->user();
        $now = time();
        $this->expectDeviceRows($parent->id, [
            $online->id => [$parent->id . ':192.0.2.1' => $now - 5],
        ]);
        Cache::put(CacheKey::get('SERVER_VLESS_ONLINE_USER', $parent->id), 4, 3600);
        Cache::put(CacheKey::get('SERVER_VLESS_ONLINE_USER', $child->id), 99, 3600);

        $this->getJson($this->endpoint() . '?id=' . $child->id)->assertOk()
            ->assertJsonPath('data.source_node_id', $parent->id)
            ->assertJsonPath('data.reported_online', 4)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.list.0.id', $online->id);
    }

    public function test_missing_parent_is_rejected_without_reading_device_state(): void
    {
        Sanctum::actingAs($this->user(true));
        $child = $this->node(['parent_id' => 999999]);
        Redis::shouldReceive('sscan')->never();
        Redis::shouldReceive('pipeline')->never();

        $this->getJson($this->endpoint() . '?id=' . $child->id)->assertStatus(400);
    }

    public function test_empty_node_returns_an_empty_list_without_replacing_existing_count(): void
    {
        Sanctum::actingAs($this->user(true));
        $node = $this->node();
        $this->expectDeviceRows($node->id, []);
        Cache::put(CacheKey::get('SERVER_VLESS_ONLINE_USER', $node->id), 7, 3600);

        $this->getJson($this->endpoint() . '?id=' . $node->id)->assertOk()
            ->assertJsonPath('data.list', [])
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.reported_online', 7);
        $this->assertSame(7, $node->online);
    }
}
