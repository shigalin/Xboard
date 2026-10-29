<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class OnlineIPTest extends TestCase
{
    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        // Exercise existing device reports with isolated real Redis keys.
        $this->redisPrefix = 'online_ip_test:' . bin2hex(random_bytes(8)) . ':';
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

    public function test_legacy_reports_normalize_ips_without_writing_history(): void
    {
        $service = new DeviceStateService();
        $service->setDevices(1, 1, ['192.0.2.10:1234', '[2001:db8::1]:443']);
        $service->setDevices(1, 2, ['192.0.2.10']);
        $records = collect($service->getOnlineIPs(1))->keyBy('ip');

        $this->assertCount(2, $records);
        $this->assertTrue($records['192.0.2.10']['is_online']);
        $this->assertTrue($records['2001:db8::1']['is_online']);
        $this->assertSame(0, (int) Redis::exists('user_recent_ips:1'));

        $service->setDevices(1, 1, []);
        $service->setDevices(1, 2, []);
        $this->assertSame([], $service->getOnlineIPs(1));
        $this->assertSame(0, $service->getDeviceCount(1));
        $this->assertSame([], $service->getUsersDevices([1]));
    }

    public function test_sequenced_reports_show_only_current_ips_and_reject_stale_reports(): void
    {
        $service = new DeviceStateService();
        $service->syncNodeDevices(1, [1 => ['192.0.2.1']], 100, true);
        $service->syncNodeDevices(1, [1 => ['2001:db8::2']], 102, true);
        $this->assertNull($service->syncNodeDevices(1, [1 => ['192.0.2.99']], 101, true));
        $this->assertSame(['2001:db8::2'], array_column($service->getOnlineIPs(1), 'ip'));
        $this->assertSame(0, (int) Redis::exists('user_recent_ips:1'));

        $service->syncNodeDevices(1, [], 103, true);
        $this->assertSame([], $service->getOnlineIPs(1));
    }

    public function test_view_filters_expired_ips_and_deduplicates_nodes_without_mutating_state(): void
    {
        $now = time();
        $fields = [
            '1:192.0.2.1' => $now - 301,
            '1:192.0.2.2' => $now - 50,
            '2:192.0.2.2' => $now - 10,
            '1:2001:db8::1' => $now - 20,
        ];
        Redis::hmset('user_devices:1', $fields);
        Redis::expire('user_devices:1', 120);
        $before = Redis::hgetall('user_devices:1');
        $ttl = Redis::ttl('user_devices:1');
        $keys = Redis::keys('*');
        $service = new DeviceStateService();

        $records = $service->getOnlineIPs(1);
        $this->assertSame(['192.0.2.2', '2001:db8::1'], array_column($records, 'ip'));
        $this->assertSame([$now - 10, $now - 20], array_column($records, 'last_seen_at'));
        $this->assertSame([true, true], array_column($records, 'is_online'));
        $this->assertSame($before, Redis::hgetall('user_devices:1'));
        $this->assertLessThanOrEqual($ttl, Redis::ttl('user_devices:1'));
        $this->assertEqualsCanonicalizing($keys, Redis::keys('*'));
    }

    public function test_online_ips_are_not_limited_to_one_hundred_addresses(): void
    {
        $ips = array_map(fn ($i) => '2001:db8::' . dechex($i), range(1, 120));
        $service = new DeviceStateService();
        $service->syncNodeDevices(1, [1 => $ips], 100, true);

        $this->assertCount(120, $service->getOnlineIPs(1));
        $this->assertSame(120, $service->getDeviceCount(1));
        $this->assertEqualsCanonicalizing($ips, array_column($service->getOnlineIPs(1), 'ip'));
        $this->assertSame(0, (int) Redis::exists('user_recent_ips:1'));
    }

    public function test_disconnect_removes_only_that_nodes_online_ips(): void
    {
        $service = new DeviceStateService();
        $service->syncNodeDevices(1, [1 => ['192.0.2.1', '2001:db8::1']], 100, false);
        $service->syncNodeDevices(2, [1 => ['192.0.2.1']], 100, false);
        $service->clearAllNodeDevices(1, 100);

        $this->assertSame(['192.0.2.1'], array_column($service->getOnlineIPs(1), 'ip'));
        $this->assertSame(1, $service->getDeviceCount(1));
        $service->clearAllNodeDevices(2, 100);
        $this->assertSame([], $service->getOnlineIPs(1));
    }
}
