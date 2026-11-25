<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UserServerTrafficMigrationTest extends TestCase
{
    public function test_merge_and_rollback_preserve_cycle_and_same_day_details_in_a_populated_database(): void
    {
        $this->travelTo(Date::parse('2026-05-01 12:00:00'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $merge = require database_path('migrations/2026_11_25_000002_merge_user_server_traffic_totals.php');
        $cycle = 'v2_user_server_traffic';
        $daily = 'v2_user_server_daily_traffic';
        $today = Date::today()->timestamp;
        $yesterday = Date::yesterday()->timestamp;
        $user = User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com', 'password' => '',
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]));

        try {
            // DDL runs outside RefreshDatabase's transaction on MySQL.
            $merge->down();
            $common = ['user_id' => $user->id, 'created_at' => $yesterday, 'updated_at' => $today];
            DB::table($cycle)->insert([
                $common + ['server_id' => 1, 'server_name' => 'Cycle name', 'billed_u' => 300],
                $common + ['server_id' => 2, 'server_name' => 'Cycle only', 'billed_u' => 400],
            ]);
            DB::table($daily)->insert([
                $common + ['server_id' => 1, 'server_name' => 'Daily name', 'record_at' => $today, 'billed_u' => 30],
                $common + ['server_id' => 3, 'server_name' => 'Reset node', 'record_at' => $today, 'billed_u' => 70],
                $common + ['server_id' => 4, 'server_name' => 'Yesterday only', 'record_at' => $yesterday, 'billed_u' => 90],
            ]);

            $merge->up();
            $this->assertFalse(Schema::hasTable($daily));
            $rows = DB::table($cycle)->where('user_id', $user->id)->orderBy('server_id')->get()->keyBy('server_id');
            $this->assertSame([1, 2, 3], $rows->keys()->all());
            $this->assertTrue((bool) $rows[1]->in_cycle);
            $this->assertSame(['Cycle name', 300], [$rows[1]->server_name, (int) $rows[1]->billed_u]);
            $this->assertSame(['Daily name', 30], [$rows[1]->daily_server_name, (int) $rows[1]->daily_billed_u]);
            $this->assertNull($rows[2]->daily_record_at);
            $this->assertFalse((bool) $rows[3]->in_cycle);
            $this->assertSame(70, (int) $rows[3]->daily_billed_u);

            DB::table($cycle)->where('user_id', $user->id)->where('server_id', 1)
                ->update(['daily_server_name' => null, 'daily_created_at' => null]);
            $merge->down();
            $this->assertFalse(Schema::hasColumn($cycle, 'daily_record_at'));
            $this->assertSame([1, 2], DB::table($cycle)->where('user_id', $user->id)
                ->orderBy('server_id')->pluck('server_id')->map(fn ($id) => (int) $id)->all());
            $restored = DB::table($daily)->where('user_id', $user->id)->orderBy('server_id')->get()->keyBy('server_id');
            $this->assertSame([1, 3], $restored->keys()->all());
            $this->assertSame(['Cycle name', $yesterday, 30], [
                $restored[1]->server_name, (int) $restored[1]->created_at, (int) $restored[1]->billed_u,
            ]);
            $this->assertSame(70, (int) $restored[3]->billed_u);
            $this->assertSame($today, (int) $restored[3]->record_at);
        } finally {
            User::whereKey($user->id)->toBase()->delete();
            if (!Schema::hasColumn($cycle, 'daily_record_at')) {
                $merge->up();
            }
        }
    }

    public function test_upgrade_and_rollback_can_resume_after_schema_changes_and_partial_copies(): void
    {
        $this->travelTo(Date::parse('2026-05-01 12:00:00'));
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $merge = require database_path('migrations/2026_11_25_000002_merge_user_server_traffic_totals.php');
        $cycle = 'v2_user_server_traffic';
        $daily = 'v2_user_server_daily_traffic';
        $today = Date::today()->timestamp;
        $user = User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com', 'password' => '',
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ]));

        try {
            $merge->down();
            // The migration copies at most 50 rows per statement.
            DB::table($daily)->insert(array_map(fn (int $node) => [
                'user_id' => $user->id, 'server_id' => $node, 'server_name' => 'Daily node',
                'billed_u' => $node, 'record_at' => $today, 'created_at' => $today, 'updated_at' => $today,
            ], range(1, 51)));

            $this->interruptMigration($merge->up(...), '/^alter table .*daily_created_at/i');
            $this->assertTrue(Schema::hasColumn($cycle, 'in_cycle'));
            $this->assertFalse(Schema::hasColumn($cycle, 'daily_created_at'));
            $this->interruptMigration($merge->up(...), '/^insert into ["`]v2_user_server_traffic["`]/i', 2);
            $this->assertSame(50, DB::table($cycle)->where('user_id', $user->id)->count());
            $this->assertSame(51, DB::table($daily)->where('user_id', $user->id)->count());
            $merge->up();
            $merge->up(); // A retry after the old table was already dropped.
            $merged = DB::table($cycle)->where('user_id', $user->id);
            $this->assertSame(51, $merged->count());
            $this->assertSame(1326, (int) $merged->sum('daily_billed_u'));
            $this->assertFalse(Schema::hasTable($daily));

            $this->interruptMigration($merge->down(...), '/^(create unique index|alter table).*user_server_daily_traffic_unique/i');
            $this->assertTrue(Schema::hasTable($daily));
            $this->assertFalse(Schema::hasIndex($daily, 'user_server_daily_traffic_unique'));
            $this->interruptMigration($merge->down(...), '/^insert into ["`]v2_user_server_daily_traffic["`]/i', 2);
            $restored = DB::table($daily)->where('user_id', $user->id)->where('record_at', $today);
            $this->assertSame(50, $restored->count());
            $this->assertSame(51, $merged->count());
            $merge->down();
            $merge->down(); // A retry after the merged columns were already dropped.
            $this->assertFalse(Schema::hasColumn($cycle, 'daily_record_at'));
            $this->assertSame(0, $merged->count());
            $this->assertSame(51, $restored->count());
            $this->assertSame(1326, (int) $restored->sum('billed_u'));
        } finally {
            User::whereKey($user->id)->toBase()->delete();
            // Restore the schema for other tests that use this database.
            $merge->up();
        }
    }

    public function test_migration_preview_includes_the_schema_removal_steps(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
        $merge = require database_path('migrations/2026_11_25_000002_merge_user_server_traffic_totals.php');
        $connection = DB::connection();
        $up = collect($connection->pretend($merge->up(...)))->pluck('query')->implode("\n");
        $down = collect($connection->pretend($merge->down(...)))->pluck('query')->implode("\n");

        $this->assertMatchesRegularExpression('/drop table ["`]v2_user_server_daily_traffic["`]/i', $up);
        $this->assertMatchesRegularExpression('/delete from ["`]v2_user_server_traffic["`]/i', $down);
        $this->assertMatchesRegularExpression(
            '/drop (?:column )?["`]daily_record_at["`]|drop table ["`]v2_user_server_traffic["`]/i',
            $down,
        );
        $this->assertFalse(Schema::hasTable('v2_user_server_daily_traffic'));
        $this->assertTrue(Schema::hasColumn('v2_user_server_traffic', 'daily_record_at'));
    }

    private function interruptMigration(callable $migrate, string $pattern, int $occurrence = 1): void
    {
        DB::connection()->beforeExecuting(function (string $sql) use ($pattern, &$occurrence) {
            if ($occurrence > 0 && preg_match($pattern, $sql) && --$occurrence === 0) {
                throw new \RuntimeException('Simulated migration interruption');
            }
        });
        try {
            $migrate();
            $this->fail('Expected the migration to reach the interrupted statement.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Simulated migration interruption', $error->getMessage());
        }
    }
}
