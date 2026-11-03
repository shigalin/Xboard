<?php

namespace Tests\Feature\Admin;

use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
    }

    private function user(bool $admin = false, array $attributes = []): User
    {
        return User::withoutEvents(fn () => User::create(array_merge([
            'email' => Helper::guid() . '@example.com', 'password' => '',
            'uuid' => Helper::guid(true), 'token' => Helper::guid(), 'is_admin' => $admin,
        ], $attributes)));
    }

    private function node(string $name = 'Test node'): Server
    {
        return Server::create([
            'name' => $name, 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
        ]);
    }

    private function report(User $user, Server $node, int $upload, int $download, float $rate = 1): void
    {
        (new TrafficFetchJob(array_merge($node->toArray(), ['rate' => $rate]),
            [$user->id => [$upload, $download]], $node->type, time()))->handle();
    }

    private function endpoint(string $action = 'getNodeTraffic'): string
    {
        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $path . '/user/' . $action;
    }

    public function test_access_is_admin_only_and_user_id_is_validated(): void
    {
        $url = $this->endpoint();
        $this->getJson($url . '?id=1')->assertForbidden();
        Sanctum::actingAs($this->user());
        $this->getJson($url . '?id=1')->assertForbidden();
        Sanctum::actingAs($this->user(true));
        foreach (['', '?id=0', '?id=-1', '?id=1.5', '?id=abc'] as $query) {
            $this->getJson($url . $query)->assertUnprocessable();
        }
        $this->getJson($url . '?id=999999')->assertStatus(400);
    }

    public function test_old_usage_is_explicitly_unattributed(): void
    {
        $user = $this->user(false, ['u' => 100, 'd' => 200]);
        Sanctum::actingAs($this->user(true));
        $this->getJson($this->endpoint() . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.list', [])
            ->assertJsonPath('data.summary.total_used', 300)
            ->assertJsonPath('data.summary.unattributed_total', 300)
            ->assertJsonPath('data.summary.recorded_from', null);
    }

    public function test_reports_keep_raw_and_billed_traffic_separate_and_isolate_users(): void
    {
        $user = $this->user(false, ['u' => 100, 'd' => 200]);
        $other = $this->user();
        $first = $this->node('First');
        $second = $this->node('Second');
        $free = $this->node('Free');
        $this->report($user, $first, 100, 200, 2);
        $this->report($user, $first, 20, 40, 0.5);
        $this->report($user, $second, 10, 20);
        $this->report($user, $free, 50, 100, 0);
        $this->report($other, $first, 900, 900);
        $first->update(['name' => 'Renamed']);
        $second->delete();

        Sanctum::actingAs($this->user(true));
        $this->getJson($this->endpoint() . '?id=' . $user->id)->assertOk()
            ->assertJsonCount(3, 'data.list')
            ->assertJsonPath('data.list.0.server_name', 'Renamed')
            ->assertJsonPath('data.list.0.upload', 120)
            ->assertJsonPath('data.list.0.download', 240)
            ->assertJsonPath('data.list.0.billed', 630)
            ->assertJsonPath('data.list.1.server_name', 'Second')
            ->assertJsonPath('data.list.1.deleted', true)
            ->assertJsonPath('data.list.2.billed', 0)
            ->assertJsonPath('data.list.2.download', 100)
            ->assertJsonPath('data.summary.total_used', 960)
            ->assertJsonPath('data.summary.attributed_total', 660)
            ->assertJsonPath('data.summary.unattributed_total', 300)
            ->assertJsonPath('data.summary.inconsistent', false);
    }

    public function test_fractional_rates_match_the_actual_accounting_counters(): void
    {
        $user = $this->user();
        $node = $this->node();
        $this->report($user, $node, 3, 7, 0.5);
        $this->report($user, $node, 3, 7, 0.5);
        $record = UserServerTraffic::firstOrFail();
        $this->assertEquals($user->fresh()->u, $record->billed_u);
        $this->assertEquals($user->fresh()->d, $record->billed_d);
    }

    public function test_reset_clears_details_even_with_a_stale_user_and_preserves_pending_plan_changes(): void
    {
        $user = $this->user();
        $node = $this->node();
        $this->report($user, $node, 100, 200);
        $user->transfer_enable = 5000;
        $this->assertTrue(app(TrafficResetService::class)->performReset($user));
        $this->assertEquals(0, $user->fresh()->u + $user->fresh()->d);
        $this->assertEquals(5000, $user->fresh()->transfer_enable);
        $this->assertSame(0, UserServerTraffic::count());
        $this->report($user, $node, 10, 20, 2);
        Sanctum::actingAs($this->user(true));
        $this->getJson($this->endpoint() . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.summary.total_used', 60)
            ->assertJsonPath('data.summary.attributed_total', 60)
            ->assertJsonPath('data.summary.unattributed_total', 0);
    }

    public function test_manual_counter_edits_clear_attribution_but_unchanged_counters_do_not(): void
    {
        $user = $this->user();
        $this->report($user, $this->node(), 100, 200);
        Sanctum::actingAs($this->user(true));
        $this->postJson($this->endpoint('update'), ['id' => $user->id, 'u' => 100, 'd' => 200])->assertOk();
        $this->assertSame(1, UserServerTraffic::count());
        $this->postJson($this->endpoint('update'), ['id' => $user->id, 'u' => 10])->assertOk();
        $this->getJson($this->endpoint() . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.list', [])
            ->assertJsonPath('data.summary.total_used', 210)
            ->assertJsonPath('data.summary.unattributed_total', 210);
    }

    public function test_empty_reports_and_deleted_users_do_not_create_details(): void
    {
        $user = $this->user();
        $node = $this->node();
        $this->report($user, $node, 0, 0);
        $this->assertSame(0, UserServerTraffic::count());
        $this->report($user, $node, 10, 20);
        $user->delete();
        $this->assertSame(0, UserServerTraffic::count());
        $this->report($user, $node, 10, 20);
        $this->assertSame(0, UserServerTraffic::count());
    }

    public function test_failed_detail_write_rolls_back_accounting(): void
    {
        $user = $this->user();
        $node = $this->node();
        UserServerTraffic::creating(fn () => throw new \RuntimeException('Detail write failed'));
        try {
            $this->report($user, $node, 100, 200);
            $this->fail('Expected the simulated write failure');
        } catch (\RuntimeException $error) {
            $this->assertSame('Detail write failed', $error->getMessage());
            $this->assertEquals(0, $user->fresh()->u + $user->fresh()->d);
            $this->assertSame(0, UserServerTraffic::count());
        } finally {
            UserServerTraffic::flushEventListeners();
        }
    }
}
