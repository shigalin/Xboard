<?php

namespace Tests\Feature\Admin;

use App\Models\StatUser;
use App\Models\User;
use App\Services\DeviceStateService;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UsageOverviewTest extends TestCase
{
    use RefreshDatabase;

    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redisPrefix = 'usage_test:' . bin2hex(random_bytes(8)) . ':';
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

    private function createUser(bool $admin = true): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com',
            'password' => '',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(true),
            'is_admin' => $admin,
        ]));
    }

    public function test_daily_usage_deduplicates_users_across_rates_and_fills_empty_days(): void
    {
        Sanctum::actingAs($this->createUser());
        $start = Carbon::today()->subDays(2)->format('Y-m-d');
        $middle = Carbon::yesterday()->format('Y-m-d');
        $end = Carbon::today()->format('Y-m-d');
        foreach ([
            [1, '1.00', $start, 'd', 10, 20],
            [1, '2.00', $start, 'd', 40, 60],
            [2, '1.00', $start, 'd', 0, 5],
            [3, '1.00', $start, 'd', 0, 0],
            [1, '1.00', $middle, 'd', 2, 3],
            [4, '1.00', $start, 'm', 1000, 1000],
            [5, '1.00', Carbon::today()->subDays(3)->format('Y-m-d'), 'd', 1000, 1000],
            [6, '1.00', Carbon::tomorrow()->format('Y-m-d'), 'd', 1000, 1000],
        ] as [$userId, $rate, $date, $type, $upload, $download]) {
            StatUser::create([
                'user_id' => $userId, 'server_rate' => $rate,
                'record_at' => Carbon::parse($date)->timestamp, 'record_type' => $type,
                'u' => $upload, 'd' => $download,
            ]);
        }

        $response = $this->getJson($this->endpoint('stat/getUsage') . '?start_date=' . $start . '&end_date=' . $end);
        $response->assertOk()->assertJsonPath('data.list', [
            ['date' => $start, 'active_users' => 2, 'traffic' => 135],
            ['date' => $middle, 'active_users' => 1, 'traffic' => 5],
            ['date' => $end, 'active_users' => 0, 'traffic' => 0],
        ])->assertJsonPath('data.summary.active_users', 2)
            ->assertJsonPath('data.summary.traffic', 140)
            ->assertJsonPath('data.summary.avg_daily_users', 1);
    }

    public function test_defaults_to_thirty_days_including_today(): void
    {
        Sanctum::actingAs($this->createUser());
        $this->getJson($this->endpoint('stat/getUsage'))
            ->assertOk()->assertJsonCount(30, 'data.list')
            ->assertJsonPath('data.summary.end_date', date('Y-m-d'));
    }

    public function test_rejects_invalid_dates_and_reversed_ranges(): void
    {
        Sanctum::actingAs($this->createUser());
        foreach ([
            'start_date=' . date('Y-m-99') . '&end_date=' . date('Y-m-d'),
            'start_date=' . date('Y-m-d') . '&end_date=' . date('Y-m-d', strtotime('yesterday')),
        ] as $query) {
            $this->getJson($this->endpoint('stat/getUsage') . '?' . $query)->assertUnprocessable();
        }
    }

    public function test_usage_and_recent_ips_require_admin_access(): void
    {
        $this->getJson($this->endpoint('stat/getUsage'))->assertForbidden();
        $this->getJson($this->endpoint('user/getRecentIPs') . '?id=1')->assertForbidden();
        Sanctum::actingAs($this->createUser(false));
        $this->getJson($this->endpoint('stat/getUsage'))->assertForbidden();
        $this->getJson($this->endpoint('user/getRecentIPs') . '?id=1')->assertForbidden();
    }

    public function test_recent_ips_validates_user_and_returns_empty_history(): void
    {
        $admin = $this->createUser();
        Sanctum::actingAs($admin);
        $this->getJson($this->endpoint('user/getRecentIPs'))->assertUnprocessable();
        $this->getJson($this->endpoint('user/getRecentIPs') . '?id=999999')->assertStatus(400);
        $this->getJson($this->endpoint('user/getRecentIPs') . '?id=' . $admin->id)
            ->assertOk()->assertJsonPath('data', []);

        $service = new DeviceStateService();
        $service->setDevices($admin->id, 1, ['[2001:db8::1]:443']);
        $this->getJson($this->endpoint('user/getRecentIPs') . '?id=' . $admin->id)
            ->assertOk()->assertJsonPath('data.0.ip', '2001:db8::1')
            ->assertJsonPath('data.0.is_online', true);
    }

    public function test_rejects_history_outside_the_retained_window_and_future_dates(): void
    {
        Sanctum::actingAs($this->createUser());
        $expired = date('Y-m-d', strtotime('-2 month', strtotime('today')));
        $this->getJson($this->endpoint('stat/getUsage') . '?start_date=' . $expired . '&end_date=' . date('Y-m-d'))
            ->assertUnprocessable();
        $this->getJson($this->endpoint('stat/getUsage') . '?start_date=0001-01-01&end_date=9999-12-31')
            ->assertUnprocessable();
    }

    public function test_retention_matches_php_month_overflow_in_application_timezone(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-04-30 12:00:00'));
        try {
            Sanctum::actingAs($this->createUser());
            $this->getJson($this->endpoint('stat/getUsage'))
                ->assertOk()->assertJsonPath('data.summary.available_from', '2026-03-03');
            $this->getJson($this->endpoint('stat/getUsage') . '?start_date=2026-03-02&end_date=2026-04-30')
                ->assertUnprocessable();
            $this->getJson($this->endpoint('stat/getUsage') . '?start_date=2026-03-03&end_date=2026-04-30')
                ->assertOk();
        } finally {
            Carbon::setTestNow();
        }
    }

}
