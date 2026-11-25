<?php

namespace Tests\Feature\Admin;

use App\Jobs\TrafficFetchJob;
use App\Models\Server;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NodeTrafficTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Date::parse('2026-05-01 12:00:00'));
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

    private function report(User $user, Server $node, int|float|string $upload, int|float|string $download, float $rate = 1, ?int $reportedAt = null): void
    {
        (new TrafficFetchJob(array_merge($node->toArray(), ['rate' => $rate]),
            [$user->id => [$upload, $download]], $node->type, $reportedAt ?? Date::now()->timestamp))->handle();
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
        foreach (['cycle', 'today'] as $period) {
            $this->getJson($url . '?id=1&period=' . $period)->assertForbidden();
        }
        Sanctum::actingAs($this->user(true));
        foreach (['', '?id=0', '?id=-1', '?id=1.5', '?id=abc'] as $query) {
            $this->getJson($url . $query)->assertUnprocessable();
        }
        $this->getJson($url . '?id=999999')->assertStatus(400);
        $this->getJson($url . '?id=999999&period=today')->assertStatus(400);
        $this->getJson($url . '?id=1&period=yesterday')->assertUnprocessable();
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
        $this->getJson($this->endpoint() . '?id=' . $user->id . '&period=today')->assertOk()
            ->assertJsonPath('data.list', [])
            ->assertJsonPath('data.summary.total_used', 0);
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
        $this->assertEquals($record->billed_u, $record->daily_billed_u);
        $this->assertEquals($record->billed_d, $record->daily_billed_d);
    }

    public function test_fractional_raw_bytes_use_the_database_rounding_of_a_running_total(): void
    {
        $user = $this->user();
        $node = $this->node();
        $this->report($user, $node, '10.5', 20.5);
        $this->report($user, $node, -0.5, '0.5');

        $row = UserServerTraffic::firstOrFail();
        $this->assertEquals($user->fresh()->u, $row->u);
        $this->assertEquals($user->fresh()->d, $row->d);
        $this->assertEquals($row->u, $row->daily_u);
        $this->assertEquals($row->d, $row->daily_d);
    }

    public function test_today_lists_the_same_nodes_and_billed_bytes_as_the_daily_dialog_after_a_reset(): void
    {
        $today = Date::today()->timestamp;
        $user = $this->user(false, ['u' => 100, 'd' => 200]);
        $other = $this->user();
        $node = $this->node('Original');
        $free = $this->node('Free');
        $yesterday = $this->node('Yesterday only');
        $this->report($user, $node, 500, 900, 1, $today - 1);
        $this->report($user, $yesterday, 200, 400, 1, $today - 1);
        $this->report($user, $node, 100, 200, 2, $today);
        $this->assertTrue(app(TrafficResetService::class)->performReset($user));
        $this->report($user, $node, 20, 40, 0.5, $today + 1);
        $this->report($user, $free, 150, 300, 0, $today + 1);
        $this->report($other, $node, 900, 900, 1, $today + 1);
        $node->update(['name' => 'Renamed']);
        $free->delete();

        Sanctum::actingAs($this->user(true));
        $this->postJson($this->endpoint('update'), ['id' => $user->id, 'u' => 0])->assertOk();
        $this->getJson($this->endpoint() . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.summary.total_used', 20)
            ->assertJsonPath('data.list', []);
        $detail = $this->getJson($this->endpoint() . '?id=' . $user->id . '&period=today')->assertOk()
            ->assertJsonCount(2, 'data.list')
            ->assertJsonPath('data.list.0.server_name', 'Renamed')
            ->assertJsonPath('data.list.0.upload', 120)
            ->assertJsonPath('data.list.0.download', 240)
            ->assertJsonPath('data.list.0.billed', 630)
            ->assertJsonPath('data.list.1.server_name', 'Free')
            ->assertJsonPath('data.list.1.deleted', true)
            ->assertJsonPath('data.list.1.billed', 0)
            ->assertJsonPath('data.summary.total_used', 630)
            ->json('data');
        $rows = collect($this->getJson($this->endpoint('fetch'))->assertOk()->json('data'))->keyBy('id');
        $this->assertSame($detail['summary']['total_used'], $rows[$user->id]['today_traffic']);
        $this->assertSame(count($detail['list']), $rows[$user->id]['today_node_count']);
        $this->assertSame([0, 0], [$rows[$user->id]['used_node_count'], $rows[$user->id]['u']]);

        $this->artisan('reset:log')->assertSuccessful();
        $this->assertSame(0, UserServerTraffic::where('server_id', $yesterday->id)->count());
        $this->assertSame(3, UserServerTraffic::where('daily_record_at', $today)->count());
    }

    public function test_existing_cycle_row_survives_cleanup_and_starts_daily_accounting_on_the_next_report(): void
    {
        $user = $this->user(false, ['u' => 100, 'd' => 200]);
        $node = $this->node();
        UserServerTraffic::create([
            'user_id' => $user->id, 'server_id' => $node->id, 'server_name' => $node->name,
            'u' => 100, 'd' => 200, 'billed_u' => 100, 'billed_d' => 200,
        ]);
        $this->artisan('reset:log')->assertSuccessful();
        $this->assertNull(UserServerTraffic::inCycle()->firstOrFail()->daily_record_at);

        $this->report($user, $node, 10, 20);
        Sanctum::actingAs($this->user(true));
        $url = $this->endpoint() . '?id=' . $user->id;
        $this->getJson($url)->assertOk()->assertJsonPath('data.summary.attributed_total', 330);
        $this->getJson($url . '&period=today')->assertOk()
            ->assertJsonPath('data.list.0.upload', 10)
            ->assertJsonPath('data.summary.total_used', 30);
    }

    public function test_list_and_daily_dialog_return_identical_fractional_billed_totals(): void
    {
        $user = $this->user();
        foreach ([326591, 369752, 135095, 182834] as $bytes) {
            $this->report($user, $this->node(), $bytes, 0, 0.01);
        }
        $this->report($this->user(), $this->node(), 50, 50);

        Sanctum::actingAs($this->user(true));
        $list = $this->getJson($this->endpoint('fetch'))->assertOk()->json('data');
        $row = collect($list)->firstWhere('id', $user->id);
        $detail = $this->getJson($this->endpoint() . '?id=' . $user->id . '&period=today')->assertOk()
            ->assertJsonCount(4, 'data.list')
            ->assertJsonPath('data.summary.unattributed_total', 0)
            ->json('data.summary');

        // The descending display order of the nodes used to make PHP round
        // the SQLite sum to a different value than the list's SQL sum.
        $this->assertSame($row['today_traffic'], $detail['total_used']);
        $this->assertSame($row['today_traffic'], $detail['attributed_total']);
    }

    public function test_inactive_cycle_counters_do_not_leak_into_new_usage_and_daily_metadata_has_a_fallback(): void
    {
        $user = $this->user();
        $node = $this->node('Last reported name');
        $createdAt = Date::today()->addHours(2)->timestamp;
        UserServerTraffic::create([
            'user_id' => $user->id, 'server_id' => $node->id, 'server_name' => $node->name,
            'in_cycle' => false, 'u' => 100, 'd' => 200, 'billed_u' => 100, 'billed_d' => 200,
            'daily_record_at' => Date::today()->timestamp, 'daily_u' => 7, 'daily_billed_u' => 7,
            'created_at' => $createdAt,
        ]);
        $node->delete();
        Sanctum::actingAs($this->user(true));
        $url = $this->endpoint() . '?id=' . $user->id;
        $this->getJson($url . '&period=today')->assertOk()
            ->assertJsonPath('data.list.0.server_name', 'Last reported name')
            ->assertJsonPath('data.list.0.deleted', true)
            ->assertJsonPath('data.summary.recorded_from', $createdAt);

        $this->report($user, $node, 10, 20);
        $this->getJson($url)->assertOk()
            ->assertJsonPath('data.list.0.upload', 10)
            ->assertJsonPath('data.summary.attributed_total', 30)
            ->assertJsonPath('data.summary.inconsistent', false);
        $this->getJson($url . '&period=today')->assertOk()
            ->assertJsonPath('data.summary.total_used', 37);
    }

    public function test_report_day_rolls_over_at_utc_plus_eight_midnight_at_the_end_of_the_month(): void
    {
        $user = $this->user();
        $node = $this->node();
        $midnight = (new \DateTimeImmutable('2026-05-01T00:00:00+08:00'))->getTimestamp();

        $this->report($user, $node, 10, 20, 1, $midnight - 1);
        $firstDay = UserServerTraffic::firstOrFail();
        $this->assertSame($midnight - 86400, $firstDay->daily_record_at);
        $this->assertSame(10, $firstDay->daily_u);
        $node->name = 'New name';
        $this->report($user, $node, 20, 40, 1, $midnight);
        $node->name = 'Old name from delayed report';
        $this->report($user, $node, 100, 200, 1, $midnight - 1);

        $row = UserServerTraffic::firstOrFail();
        $this->assertSame(1, UserServerTraffic::count());
        $this->assertSame($midnight, $row->daily_record_at);
        $this->assertSame([20, 40, 'New name'], [$row->daily_u, $row->daily_d, $row->daily_server_name]);
        $this->assertSame([130, 260], [$row->u, $row->d]);
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
        $this->assertSame(0, UserServerTraffic::inCycle()->count());
        $this->report($user, $node, 10, 20, 2);
        Sanctum::actingAs($this->user(true));
        $this->getJson($this->endpoint() . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.summary.total_used', 60)
            ->assertJsonPath('data.summary.attributed_total', 60)
            ->assertJsonPath('data.summary.unattributed_total', 0);
        $this->getJson($this->endpoint() . '?id=' . $user->id . '&period=today')->assertOk()
            ->assertJsonCount(1, 'data.list')
            ->assertJsonPath('data.summary.total_used', 360);
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

    public function test_failed_combined_detail_write_rolls_back_accounting(): void
    {
        $user = $this->user();
        $node = $this->node();
        $sqlite = DB::getDriverName() === 'sqlite';
        $observed = false;
        DB::connection()->beforeExecuting(function (string $sql) use ($sqlite, &$observed) {
            if (str_starts_with($sql, 'insert into') && str_contains($sql, 'v2_user_server_traffic')) {
                $observed = true;
                if (!$sqlite) {
                    throw new \RuntimeException('Detail write failed');
                }
            }
        });
        if ($sqlite) {
            DB::unprepared("CREATE TEMP TRIGGER fail_detail BEFORE INSERT ON v2_user_server_traffic
                BEGIN SELECT RAISE(ABORT, 'Detail write failed'); END");
        }
        try {
            $this->report($user, $node, 100, 200);
            $this->fail('Expected the simulated write failure');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('Detail write failed', $error->getMessage());
            $this->assertTrue($observed);
            if ($sqlite) {
                $this->assertInstanceOf(QueryException::class, $error);
            }
            $this->assertEquals(0, $user->fresh()->u + $user->fresh()->d);
            $this->assertSame(0, UserServerTraffic::count());
        }
    }

    public function test_nonfinite_traffic_is_rejected_before_updating_accounting(): void
    {
        $user = $this->user();
        $node = $this->node();

        foreach ([INF, NAN, '1e400', PHP_FLOAT_MAX] as $value) {
            try {
                $this->report($user, $node, $value, 20, 2);
                $this->fail('Expected a nonfinite traffic error');
            } catch (\InvalidArgumentException $error) {
                $this->assertStringContainsString('finite numbers', $error->getMessage());
            }
        }
        $this->assertEquals(0, $user->fresh()->u + $user->fresh()->d);
        $this->assertSame(0, UserServerTraffic::count());
    }
}
