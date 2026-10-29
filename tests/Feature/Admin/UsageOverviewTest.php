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
        foreach ([
            [1, '1.00', '2026-10-27', 'd', 10, 20],
            [1, '2.00', '2026-10-27', 'd', 40, 60],
            [2, '1.00', '2026-10-27', 'd', 0, 5],
            [3, '1.00', '2026-10-27', 'd', 0, 0],
            [1, '1.00', '2026-10-28', 'd', 2, 3],
            [4, '1.00', '2026-10-27', 'm', 1000, 1000],
            [5, '1.00', '2026-10-26', 'd', 1000, 1000],
            [6, '1.00', '2026-10-30', 'd', 1000, 1000],
        ] as [$userId, $rate, $date, $type, $upload, $download]) {
            StatUser::create([
                'user_id' => $userId, 'server_rate' => $rate,
                'record_at' => Carbon::parse($date)->timestamp, 'record_type' => $type,
                'u' => $upload, 'd' => $download,
            ]);
        }

        $response = $this->getJson($this->endpoint('stat/getUsage') . '?start_date=2026-10-27&end_date=2026-10-29');
        $response->assertOk()->assertJsonPath('data.list', [
            ['date' => '2026-10-27', 'active_users' => 2, 'traffic' => 135],
            ['date' => '2026-10-28', 'active_users' => 1, 'traffic' => 5],
            ['date' => '2026-10-29', 'active_users' => 0, 'traffic' => 0],
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
            'start_date=2026-02-30&end_date=2026-10-29',
            'start_date=2026-10-29&end_date=2026-10-28',
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
}
