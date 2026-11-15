<?php

namespace Tests\Unit\Services;

use App\Services\DeviceStateService;
use Illuminate\Support\Facades\Redis;
use Mockery;
use Tests\TestCase;

class NodeOnlineUserStatesTest extends TestCase
{
    private function forbidStateChangesAndGlobalScans(): void
    {
        foreach (['scan', 'keys', 'expire', 'set', 'sadd', 'srem', 'hdel', 'del'] as $command) {
            Redis::shouldReceive($command)->never();
        }
    }

    private function pipelineRows(array $rows): void
    {
        Redis::shouldReceive('pipeline')->once()->andReturnUsing(function ($callback) use ($rows) {
            $pipe = Mockery::mock();
            foreach (array_keys($rows) as $userId) {
                $pipe->shouldReceive('hgetall')->once()->with('user_devices:' . $userId);
            }
            $callback($pipe);
            return array_values($rows);
        });
    }

    public function test_filters_expired_and_other_node_fields_without_mutation_or_global_scan(): void
    {
        $this->forbidStateChangesAndGlobalScans();
        $now = time();
        Redis::shouldReceive('sscan')->once()->with('device:node_index:7', null, ['count' => 200])
            ->andReturn(['0', ['3', 'bad', '0', '-1', '4', '5', '6']]);
        $this->pipelineRows([
            3 => [
                '7:192.0.2.1' => $now - 299,
                '7:2001:db8::1' => $now - 10,
                '7:192.0.2.99' => $now - 301,
                '70:198.51.100.1' => $now,
                '7:' => $now,
            ],
            4 => ['7:192.0.2.2' => $now - 301],
            5 => ['70:192.0.2.3' => $now],
            6 => [],
        ]);

        $this->assertSame([
            3 => ['online_ip_count' => 2, 'last_seen_at' => $now - 10],
        ], (new DeviceStateService())->getNodeOnlineUserStates(7));
    }

    public function test_bounds_pipelines_and_deduplicates_members_across_scan_pages(): void
    {
        $this->forbidStateChangesAndGlobalScans();
        $members = array_map('strval', range(1, 205));
        Redis::shouldReceive('sscan')->once()->with('device:node_index:7', null, ['count' => 200])
            ->andReturn(['42', $members]);
        Redis::shouldReceive('sscan')->once()->with('device:node_index:7', '42', ['count' => 200])
            ->andReturn(['0', ['1', '205']]);
        $now = time();
        $rows = [];
        foreach (range(1, 205) as $userId) {
            $rows[$userId] = ['7:192.0.2.1' => $now - 10];
        }
        $this->pipelineRows(array_slice($rows, 0, 200, true));
        $this->pipelineRows(array_slice($rows, 200, null, true));

        $states = (new DeviceStateService())->getNodeOnlineUserStates(7);
        $this->assertCount(205, $states);
        foreach ($states as $state) {
            $this->assertSame(['online_ip_count' => 1, 'last_seen_at' => $now - 10], $state);
        }
    }

    public function test_missing_index_does_not_scan_all_users_or_fall_back_to_stale_counts(): void
    {
        $this->forbidStateChangesAndGlobalScans();
        Redis::shouldReceive('sscan')->once()->with('device:node_index:7', null, ['count' => 200])
            ->andReturn(false);
        Redis::shouldReceive('pipeline')->never();

        $this->assertSame([], (new DeviceStateService())->getNodeOnlineUserStates(7));
    }
}
