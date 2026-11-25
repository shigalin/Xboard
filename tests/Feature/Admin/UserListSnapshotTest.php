<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Utils\Helper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A second connection records node traffic and commits between the user
 * list's page query and its summary, as a concurrent report would. The data
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
            AdminAuditLog::whereIn('admin_id', $this->userIds)->delete();
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

    public function test_sorted_page_and_traffic_summary_share_one_snapshot_under_read_committed(): void
    {
        $this->travelTo(Date::parse('2026-05-01 12:00:00'));
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            // PostgreSQL's default; the report then commits between two statements.
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        $admin = $this->createUser(true);
        $user = $this->createUser();
        Sanctum::actingAs($admin);
        User::whereKey($user->id)->toBase()->update(['u' => 100, 'd' => 200]);
        $today = Date::today()->timestamp;
        UserServerTraffic::create([
            'user_id' => $user->id, 'server_id' => 1, 'server_name' => 'node',
            'u' => 100, 'd' => 200, 'billed_u' => 100, 'billed_d' => 200,
            'daily_record_at' => $today, 'daily_billed_u' => 100, 'daily_billed_d' => 200,
        ]);

        $reported = false;
        DB::listen(function (QueryExecuted $query) use (&$reported, $admin, $today) {
            if ($reported || $query->connectionName !== DB::getDefaultConnection()
                || !str_contains($query->sql, 'limit') || !preg_match('/from ["`]v2_user["`]/', $query->sql)) {
                return;
            }
            $reported = true;
            DB::connection('snapshot_other')->transaction(function ($other) use ($admin, $today) {
                $other->table('v2_user')->where('id', $admin->id)->update(['u' => 500]);
                $other->table('v2_user_server_traffic')->insert([
                    'user_id' => $admin->id, 'server_id' => 2, 'server_name' => 'new node',
                    'u' => 500, 'billed_u' => 500, 'daily_record_at' => $today, 'daily_billed_u' => 500,
                    'created_at' => $today, 'updated_at' => $today,
                ]);
            });
        });

        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $filters = [
            'pageSize' => 2,
            'filter' => [['id' => 'id', 'value' => [$user->id, $admin->id]]],
            'sort' => [['id' => 'today_traffic', 'desc' => true]],
        ];
        $rows = $this->getJson("/api/v2/{$securePath}/user/fetch?" . http_build_query($filters))->assertOk()->json('data');
        $this->assertTrue($reported);
        $this->assertSame([$user->id, $admin->id], array_column($rows, 'id'));
        $this->assertSame([300, 0], array_column($rows, 'total_used'));
        $this->assertSame([300, 0], array_column($rows, 'today_traffic'));
        $this->assertSame([1, 0], array_column($rows, 'used_node_count'));
        $this->assertSame([1, 0], array_column($rows, 'today_node_count'));
        $this->assertSame(500, (int) User::whereKey($admin->id)->value('u'));
        $this->assertFalse(AdminAuditLog::where('admin_id', $admin->id)->exists());
    }

    public function test_sorted_csv_uses_one_snapshot_when_a_report_moves_a_user_across_chunks(): void
    {
        $this->travelTo(Date::parse('2026-05-01 12:00:00'));
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        Sanctum::actingAs($this->createUser(true));
        [$late, $leader, $middle] = DB::transaction(fn () => [
            $this->createUser(), $this->createUser(),
            array_map(fn () => $this->createUser(), range(1, 499)),
        ]);
        $today = Date::today()->timestamp;
        UserServerTraffic::create([
            'user_id' => $leader->id, 'server_id' => 1, 'server_name' => 'leader',
            'daily_record_at' => $today, 'daily_billed_u' => 300,
        ]);

        $reported = false;
        DB::listen(function (QueryExecuted $query) use (&$reported, $late, $today) {
            if ($reported || $query->connectionName !== DB::getDefaultConnection()
                || !str_contains($query->sql, 'limit 500') || !preg_match('/from ["`]v2_user["`]/', $query->sql)) {
                return;
            }
            $reported = true;
            // The last row would move to the first chunk after this report.
            DB::connection('snapshot_other')->transaction(function ($other) use ($late, $today) {
                $other->table('v2_user')->where('id', $late->id)->update(['u' => 500]);
                $other->table('v2_user_server_traffic')->insert([
                    'user_id' => $late->id, 'server_id' => 2, 'server_name' => 'late node',
                    'u' => 500, 'billed_u' => 500, 'daily_record_at' => $today, 'daily_billed_u' => 500,
                    'created_at' => $today, 'updated_at' => $today,
                ]);
            });
        });

        $ids = [$late->id, $leader->id, ...array_map(fn ($user) => $user->id, $middle)];
        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $csv = $this->postJson("/api/v2/{$securePath}/user/dumpCSV", [
            'scope' => 'filtered',
            'filter' => [['id' => 'id', 'value' => $ids]],
            'sort' => 'today_traffic', 'sort_type' => 'DESC',
        ])->assertOk()->streamedContent();
        $emails = array_map(fn ($line) => str_getcsv($line, escape: '')[0], array_slice(explode("\n", trim($csv)), 1));
        $expected = [$leader->email, ...array_map(fn ($user) => $user->email, array_reverse($middle)), $late->email];
        $this->assertTrue($reported);
        $this->assertCount(501, array_unique($emails));
        $this->assertSame($expected, $emails);
        $this->assertSame(500, (int) $late->fresh()->u);
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
