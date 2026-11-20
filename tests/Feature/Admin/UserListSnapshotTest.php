<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Models\UserServerTraffic;
use App\Utils\Helper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A second connection resets a user's traffic and commits between the user
 * list's page query and its node count, as a concurrent reset would. The data
 * is committed, so this test does not use RefreshDatabase: it migrates, then
 * deletes its rows. SQLite uses a shared WAL file instead of :memory:.
 */
class UserListSnapshotTest extends TestCase
{
    private ?string $sqliteFile = null;
    private array $userIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('database.connections.' . config('database.default'));
        if ($config['driver'] === 'sqlite') {
            $this->sqliteFile = tempnam(sys_get_temp_dir(), 'xboard-snapshot-');
            $config = [
                'driver' => 'sqlite', 'database' => $this->sqliteFile, 'prefix' => '',
                'foreign_key_constraints' => true, 'busy_timeout' => 1000, 'journal_mode' => 'wal',
            ];
            config(['database.connections.snapshot_main' => $config, 'database.default' => 'snapshot_main']);
        }
        config(['database.connections.snapshot_other' => $config]);
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        try {
            UserServerTraffic::whereIn('user_id', $this->userIds)->delete();
            User::whereIn('id', $this->userIds)->delete();
            DB::disconnect('snapshot_other');
            if ($this->sqliteFile !== null) {
                DB::disconnect('snapshot_main');
            }
        } finally {
            parent::tearDown();
            if ($this->sqliteFile !== null) {
                foreach (['', '-wal', '-shm'] as $suffix) {
                    @unlink($this->sqliteFile . $suffix);
                }
            }
        }
    }

    public function test_usage_and_node_count_come_from_one_snapshot_under_read_committed(): void
    {
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // PostgreSQL's default; the reset then commits between two statements.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        Sanctum::actingAs($this->createUser(true));
        $user = $this->createUser();
        User::whereKey($user->id)->toBase()->update(['u' => 100, 'd' => 200]);
        UserServerTraffic::create([
            'user_id' => $user->id, 'server_id' => 1, 'server_name' => 'node',
            'u' => 100, 'd' => 200, 'billed_u' => 100, 'billed_d' => 200,
        ]);

        $reset = false;
        DB::listen(function (QueryExecuted $query) use (&$reset, $user) {
            if ($reset || $query->connectionName !== DB::getDefaultConnection()
                || !str_contains($query->sql, 'limit') || !preg_match('/from ["`]v2_user["`]/', $query->sql)) {
                return;
            }
            $reset = true;
            DB::connection('snapshot_other')->transaction(function ($other) use ($user) {
                $other->table('v2_user')->where('id', $user->id)->update(['u' => 0, 'd' => 0]);
                $other->table('v2_user_server_traffic')->where('user_id', $user->id)->delete();
            });
        });

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $row = collect($this->getJson("/api/v2/{$securePath}/user/fetch?pageSize=50")->assertOk()->json('data'))
            ->firstWhere('id', $user->id);
        $this->assertTrue($reset);
        $this->assertSame([300, 1], [$row['total_used'], $row['used_node_count']]);
        $this->assertSame(0, (int) User::whereKey($user->id)->value('u'));
    }

    private function createUser(bool $admin = false): User
    {
        $user = User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com', 'password' => '', 'uuid' => Helper::guid(true),
            'token' => Helper::guid(), 'is_admin' => $admin,
        ]));
        $this->userIds[] = $user->id;
        return $user;
    }
}
