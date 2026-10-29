<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class RecentIPTest extends TestCase
{
    private string $redisPrefix;

    protected function setUp(): void
    {
        parent::setUp();
        // This suite needs a real Redis connection to exercise the Lua sequence
        // fence. Isolate its keys even when other tests share the same database.
        $this->redisPrefix = 'recent_ip_test:' . bin2hex(random_bytes(8)) . ':';
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

    public function test_legacy_reports_normalize_and_deduplicate_ipv4_and_ipv6(): void
    {
        $service = new DeviceStateService();
        $service->setDevices(1, 1, ['192.0.2.10:1234', '[2001:db8::1]:443']);
        $service->setDevices(1, 2, ['192.0.2.10']);
        $records = collect($service->getRecentIPs(1))->keyBy('ip');

        $this->assertCount(2, $records);
        $this->assertTrue($records['192.0.2.10']['is_online']);
        $this->assertTrue($records['2001:db8::1']['is_online']);

        $service->setDevices(1, 1, []);
        $service->setDevices(1, 2, []);
        $records = $service->getRecentIPs(1);
        $this->assertCount(2, $records);
        $this->assertSame([false, false], array_column($records, 'is_online'));
        $this->assertGreaterThan(300, Redis::ttl('user_recent_ips:1'));
    }

    public function test_sequenced_reports_retain_history_without_accepting_stale_ips(): void
    {
        $service = new DeviceStateService();
        $service->syncNodeDevices(1, [1 => ['192.0.2.1']], 100, true);
        $service->syncNodeDevices(1, [1 => ['2001:db8::2']], 102, true);
        $this->assertNull($service->syncNodeDevices(1, [1 => ['192.0.2.99']], 101, true));
        $records = collect($service->getRecentIPs(1))->keyBy('ip');
        $this->assertCount(2, $records);
        $this->assertFalse($records['192.0.2.1']['is_online']);
        $this->assertTrue($records['2001:db8::2']['is_online']);

        $service->syncNodeDevices(1, [], 103, true);
        $this->assertSame([false, false], array_column($service->getRecentIPs(1), 'is_online'));
    }

    public function test_history_expires_and_is_sorted_and_bounded(): void
    {
        $service = new DeviceStateService();
        Redis::zadd('user_recent_ips:1', time() - 86401, '192.0.2.1');
        Redis::zadd('user_recent_ips:1', time() - 50, '192.0.2.2');
        Redis::zadd('user_recent_ips:1', time() - 20, '2001:db8::1');
        Redis::hset('user_devices:1', '1:192.0.2.2', time() - 301);
        $records = $service->getRecentIPs(1);
        $this->assertSame(['2001:db8::1', '192.0.2.2'], array_column($records, 'ip'));
        $this->assertSame([false, false], array_column($records, 'is_online'));

        $ips = array_map(fn ($i) => '2001:db8::' . dechex($i), range(1, 120));
        $service->syncNodeDevices(1, [1 => $ips], 100, true);
        $this->assertCount(100, $service->getRecentIPs(1));
        $this->assertSame(100, Redis::zcard('user_recent_ips:1'));

        $service->setDevices(2, 2, $ips);
        $this->assertSame(100, Redis::zcard('user_recent_ips:2'));
    }

    public function test_delta_reports_and_disconnect_preserve_history_and_other_nodes(): void
    {
        $service = new DeviceStateService();
        $service->syncNodeDevices(1, [1 => ['192.0.2.1', '2001:db8::1']], 100, false);
        $service->syncNodeDevices(2, [1 => ['192.0.2.1']], 100, false);
        $service->clearAllNodeDevices(1, 100);
        $records = collect($service->getRecentIPs(1))->keyBy('ip');
        $this->assertCount(2, $records);
        $this->assertTrue($records['192.0.2.1']['is_online']);
        $this->assertFalse($records['2001:db8::1']['is_online']);
    }
}
