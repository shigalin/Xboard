<?php

namespace Tests\Feature\Admin;

use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Services\TrafficResetService;
use App\Utils\Helper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Two connections share one WAL SQLite file. After the first statement of each
 * transaction, the second connection tries to commit traffic for the same user,
 * as a concurrent node report would. A transaction that starts with a read lets
 * that commit through and then fails to write with "database is locked".
 */
class NodeTrafficSqliteConcurrencyTest extends TestCase
{
    private string $database;
    private bool $otherWriterBlocked = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is required.');
        }

        $this->database = tempnam(sys_get_temp_dir(), 'xboard-sqlite-lock-');
        // The competing connection must not wait, so a held writer lock shows up.
        foreach (['main' => 1000, 'other' => 0] as $name => $busyTimeout) {
            config(["database.connections.lock_$name" => [
                'driver' => 'sqlite', 'database' => $this->database, 'prefix' => '',
                'foreign_key_constraints' => true, 'busy_timeout' => $busyTimeout, 'journal_mode' => 'wal',
            ]]);
        }
        config(['database.default' => 'lock_main']);
        Artisan::call('migrate', ['--force' => true]);
        DB::connection('lock_other')->getPdo();

        Bus::fake();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
    }

    protected function tearDown(): void
    {
        DB::disconnect('lock_main');
        DB::disconnect('lock_other');
        parent::tearDown();
        foreach (['', '-wal', '-shm'] as $suffix) {
            @unlink($this->database . $suffix);
        }
    }

    private function user(array $attributes = []): User
    {
        return User::withoutEvents(fn () => User::create(array_merge([
            'email' => Helper::guid() . '@example.com', 'password' => '',
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ], $attributes)));
    }

    private function node(): Server
    {
        return Server::create([
            'name' => 'Test node', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
        ]);
    }

    private function report(User $user, Server $node, int $upload, int $download): void
    {
        (new TrafficFetchJob(array_merge($node->toArray(), ['rate' => 1]),
            [$user->id => [$upload, $download]], $node->type, time()))->handle();
    }

    private function competeWithNodeReports(User $user): void
    {
        $armed = false;
        Event::listen(TransactionBeginning::class, function (TransactionBeginning $event) use (&$armed) {
            $armed = $event->connectionName === 'lock_main';
        });
        DB::listen(function (QueryExecuted $query) use (&$armed, $user) {
            if (!$armed || $query->connectionName !== 'lock_main') {
                return;
            }
            $armed = false;
            try {
                DB::connection('lock_other')->table('v2_user')->where('id', $user->id)->increment('u', 7);
            } catch (QueryException) {
                $this->otherWriterBlocked = true;
            }
        });
    }

    public function test_manual_reset_holds_the_writer_lock_during_node_reports(): void
    {
        $plan = Plan::create([
            'group_id' => 1, 'transfer_enable' => 1111, 'name' => 'Lock test', 'show' => 1, 'sort' => 0,
            'renew' => 1, 'sell' => 1, 'prices' => [Plan::PERIOD_MONTHLY => 11],
            'reset_traffic_method' => Plan::RESET_TRAFFIC_MONTHLY,
        ]);
        $user = $this->user(['plan_id' => $plan->id, 'expired_at' => time() + 30 * 86400]);
        $this->report($user, $this->node(), 100, 200);
        $this->competeWithNodeReports($user);

        $this->assertTrue(app(TrafficResetService::class)->manualReset(User::findOrFail($user->id)));
        $this->assertTrue($this->otherWriterBlocked);
        $this->assertEquals(0, $user->fresh()->u + $user->fresh()->d);
        $this->assertSame(0, UserServerTraffic::count());
    }

    public function test_node_report_holds_the_writer_lock_during_other_reports(): void
    {
        $user = $this->user();
        $this->competeWithNodeReports($user);

        $this->report($user, $this->node(), 100, 200);
        $this->assertTrue($this->otherWriterBlocked);
        $this->assertEquals(300, $user->fresh()->u + $user->fresh()->d);
        $this->assertEquals(300, UserServerTraffic::sum(DB::raw('billed_u + billed_d')));
    }

    public function test_admin_save_holds_the_writer_lock_during_node_reports(): void
    {
        $user = $this->user();
        Sanctum::actingAs($this->user(['is_admin' => true]));
        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->competeWithNodeReports($user);

        $this->postJson('/api/v2/' . $path . '/user/update', ['id' => $user->id, 'remarks' => 'checked'])->assertOk();
        $this->assertTrue($this->otherWriterBlocked);
        $this->assertSame('checked', $user->fresh()->remarks);
    }
}
