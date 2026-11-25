<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    private const CYCLE = 'v2_user_server_traffic';
    private const DAILY = 'v2_user_server_daily_traffic';
    private const CLEANUP_INDEX = 'user_server_traffic_daily_cleanup';
    private const MERGED_COLUMNS = [
        'in_cycle', 'daily_record_at', 'daily_created_at', 'daily_server_name',
        'daily_u', 'daily_d', 'daily_billed_u', 'daily_billed_d',
    ];

    public function up(): void
    {
        // Laravel pretends schema reads are empty. Show the full migration
        // plan in a preview, including the steps after the copy.
        $preview = DB::connection()->pretending();
        $missing = $preview ? self::MERGED_COLUMNS : array_diff(self::MERGED_COLUMNS, Schema::getColumnListing(self::CYCLE));
        $hasIndex = !$preview && Schema::hasIndex(self::CYCLE, self::CLEANUP_INDEX);
        Schema::table(self::CYCLE, function (Blueprint $table) use ($missing, $hasIndex) {
            foreach ($missing as $column) {
                match ($column) {
                    'in_cycle' => $table->boolean($column)->default(true),
                    'daily_record_at', 'daily_created_at' => $table->integer($column)->nullable(),
                    'daily_server_name' => $table->string($column)->nullable(),
                    default => $table->bigInteger($column)->default(0),
                };
            }
            if (!$hasIndex) {
                $table->index(['in_cycle', 'daily_record_at'], self::CLEANUP_INDEX);
            }
        });

        // The source is dropped only after all chunks are copied. Its
        // absence also covers interruption after the drop but before bookkeeping.
        if (!$preview && !Schema::hasTable(self::DAILY)) {
            return;
        }

        // Preserve today's detail if the separate daily table was already used.
        // Earlier days are outside the view and would be removed by reset:log.
        DB::table(self::DAILY)->where('record_at', Date::today()->timestamp)
            ->chunkById(50, function ($rows) {
                $values = $rows->map(fn ($row) => [
                    'user_id' => $row->user_id,
                    'server_id' => $row->server_id,
                    'server_name' => $row->server_name,
                    'created_at' => $row->created_at,
                    'updated_at' => $row->updated_at,
                    'in_cycle' => false,
                    'daily_record_at' => $row->record_at,
                    'daily_created_at' => $row->created_at,
                    'daily_server_name' => $row->server_name,
                    'daily_u' => $row->u,
                    'daily_d' => $row->d,
                    'daily_billed_u' => $row->billed_u,
                    'daily_billed_d' => $row->billed_d,
                ])->all();
                DB::table(self::CYCLE)->upsert($values, ['user_id', 'server_id'], [
                    'daily_record_at', 'daily_created_at', 'daily_server_name',
                    'daily_u', 'daily_d', 'daily_billed_u', 'daily_billed_d',
                ]);
            });
        Schema::drop(self::DAILY);
    }

    public function down(): void
    {
        $daily = require __DIR__ . '/2026_11_25_000001_create_user_server_daily_traffic_table.php';
        $daily->up();

        $preview = DB::connection()->pretending();
        $columns = $preview ? self::MERGED_COLUMNS : array_intersect(self::MERGED_COLUMNS, Schema::getColumnListing(self::CYCLE));
        if ($columns === self::MERGED_COLUMNS) {
            $this->restoreDailyTraffic();
            DB::table(self::CYCLE)->where('in_cycle', false)->delete();
        }

        // SQLite may rebuild the table when dropping columns. Make the schema
        // removal atomic there so a retry always sees a complete set of fields
        // to copy, or a schema whose copy has already finished.
        $removeColumns = function () use ($columns, $preview) {
            $hasIndex = $preview || Schema::hasIndex(self::CYCLE, self::CLEANUP_INDEX);
            Schema::table(self::CYCLE, function (Blueprint $table) use ($columns, $hasIndex) {
                if ($hasIndex) {
                    $table->dropIndex(self::CLEANUP_INDEX);
                }
                if ($columns !== []) {
                    $table->dropColumn(array_values($columns));
                }
            });
        };
        if (!$preview && DB::getDriverName() === 'sqlite') {
            DB::transaction($removeColumns);
        } else {
            $removeColumns();
        }
    }

    private function restoreDailyTraffic(): void
    {
        DB::table(self::CYCLE)->where('daily_record_at', Date::today()->timestamp)
            ->chunkById(50, function ($rows) {
                $values = $rows->map(fn ($row) => [
                    'user_id' => $row->user_id,
                    'server_id' => $row->server_id,
                    'server_name' => $row->daily_server_name ?? $row->server_name,
                    'record_at' => $row->daily_record_at,
                    'u' => $row->daily_u,
                    'd' => $row->daily_d,
                    'billed_u' => $row->daily_billed_u,
                    'billed_d' => $row->daily_billed_d,
                    'created_at' => $row->daily_created_at ?? $row->created_at,
                    'updated_at' => $row->updated_at,
                ])->all();
                DB::table(self::DAILY)->upsert($values, ['user_id', 'record_at', 'server_id'], [
                    'server_name', 'u', 'd', 'billed_u', 'billed_d', 'created_at', 'updated_at',
                ]);
            });
    }
};
