<?php

namespace App\Models;

use App\Database\SQLiteConnection;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

class UserServerTraffic extends Model
{
    protected $table = 'v2_user_server_traffic';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];

    public function scopeInCycle(Builder $query): Builder
    {
        return $query->where('in_cycle', true);
    }

    public static function clearCycle(int $userId): void
    {
        static::inCycle()->where('user_id', $userId)->toBase()->update([
            'in_cycle' => false,
            'u' => 0,
            'd' => 0,
            'billed_u' => 0,
            'billed_d' => 0,
        ]);
    }

    public static function prepareSqliteReport(SQLiteConnection $connection): Closure
    {
        $query = $connection->table((new static())->getTable());
        $values = self::reportValues(0, 0, '', 0, ['u' => 0, 'd' => 0, 'billed_u' => 0, 'billed_d' => 0]);
        $sql = $query->getGrammar()->compileUpsert($query, [$values], ['user_id', 'server_id'], self::reportUpdates($query));

        // Prepare once per batch, before taking SQLite's writer lock.
        return $connection->prepareBatchWrite($sql);
    }

    public static function recordReport(
        int $userId, int $serverId, string $name, int $recordAt, array $bytes, ?Closure $write,
    ): void {
        $values = self::reportValues($userId, $serverId, $name, $recordAt, $bytes);
        if ($write !== null) {
            $write(array_values($values));
            return;
        }

        // The report API accepts numeric strings and fractional raw bytes.
        // On integer-only databases, add numeric deltas before the column
        // rounds the result, just as incrementEach() did.
        $literals = [];
        foreach ($bytes as $column => $value) {
            if (!is_int($value)) {
                if (!is_numeric($value) || !is_finite((float) $value)) {
                    throw new \InvalidArgumentException('Traffic deltas must be finite numbers.');
                }
                $literals[$column] = trim((string) $value);
                $values[$column] = $values['daily_' . $column] = DB::raw($literals[$column]);
            }
        }
        $query = (new static())->newQuery()->toBase();
        $query->upsert($values, ['user_id', 'server_id'], self::reportUpdates($query, $literals));
    }

    private static function reportValues(int $userId, int $serverId, string $name, int $recordAt, array $bytes): array
    {
        $now = time();
        return [
            'billed_d' => $bytes['billed_d'],
            'billed_u' => $bytes['billed_u'],
            'created_at' => $now,
            'd' => $bytes['d'],
            'daily_billed_d' => $bytes['billed_d'],
            'daily_billed_u' => $bytes['billed_u'],
            'daily_created_at' => $now,
            'daily_d' => $bytes['d'],
            'daily_record_at' => $recordAt,
            'daily_server_name' => $name,
            'daily_u' => $bytes['u'],
            'in_cycle' => 1,
            'server_id' => $serverId,
            'server_name' => $name,
            'u' => $bytes['u'],
            'updated_at' => $now,
            'user_id' => $userId,
        ];
    }

    private static function reportUpdates(QueryBuilder $query, array $literals = []): array
    {
        $table = $query->from;
        $connection = $query->getConnection();
        $incoming = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => $connection->getConfig('use_upsert_alias')
                ? fn (string $column) => "laravel_upsert_alias.{$column}"
                : fn (string $column) => "VALUES({$column})",
            default => fn (string $column) => "excluded.{$column}",
        };
        $incomingDay = $incoming('daily_record_at');
        $sameDay = "{$table}.daily_record_at = {$incomingDay}";
        $newDay = "{$table}.daily_record_at IS NULL OR {$table}.daily_record_at < {$incomingDay}";
        $updates = ['server_name', 'updated_at'];
        foreach (['u', 'd', 'billed_u', 'billed_d'] as $column) {
            $daily = 'daily_' . $column;
            $delta = $literals[$column] ?? $incoming($column);
            $updates[$column] = DB::raw("CASE WHEN {$table}.in_cycle THEN {$table}.{$column} ELSE 0 END + {$delta}");
            $updates[$daily] = DB::raw("CASE WHEN {$sameDay} THEN {$table}.{$daily} + " . ($literals[$column] ?? $incoming($daily))
                . " WHEN {$newDay} THEN " . $incoming($daily) . " ELSE {$table}.{$daily} END");
        }
        $updates['created_at'] = DB::raw("CASE WHEN {$table}.in_cycle THEN {$table}.created_at ELSE "
            . $incoming('created_at') . ' END');
        $updates['daily_created_at'] = DB::raw("CASE WHEN {$newDay} THEN "
            . $incoming('daily_created_at') . " ELSE {$table}.daily_created_at END");
        $updates['daily_server_name'] = DB::raw("CASE WHEN {$sameDay} OR {$newDay} THEN "
            . $incoming('daily_server_name') . " ELSE {$table}.daily_server_name END");
        // MySQL evaluates these assignments in order. Change the cycle flag and
        // day only after all expressions that check their previous values.
        $updates[] = 'in_cycle';
        $updates['daily_record_at'] = DB::raw("CASE WHEN {$newDay} THEN {$incomingDay} ELSE {$table}.daily_record_at END");

        return $updates;
    }
}
