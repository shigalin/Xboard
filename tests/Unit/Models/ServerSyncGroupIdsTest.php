<?php

namespace Tests\Unit\Models;

use App\Models\Server;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ServerSyncGroupIdsTest extends TestCase
{
    /**
     * Rows full sync's whereIn accepted keep their bound values; rows it
     * rejected keep only top-level strings that are exactly a group ID.
     */
    public static function shapes(): array
    {
        return [
            // Accepted by full sync before: unchanged.
            'string list' => ['["1","2"]', ['1', '2']],
            'integer list' => ['[1]', [1]],
            'boolean' => ['[true]', [true]],
            'zero-padded element' => ['["01"]', ['01']],
            'object' => ['{"a":"1"}', ['1']],
            'single nested' => ['[["1"]]', ['1']],
            'deep single nested' => ['[[["1"]]]', ['1']],
            'null element' => ['[null]', [null]],
            'empty list' => ['[]', []],
            'null' => ['null', []],
            // Rejected by full sync before: exact top-level string IDs only.
            'multi-value nested' => ['[["1","2"]]', []],
            'mixed nested' => ['["1",["2","3"]]', ['1']],
            'empty nested' => ['["1",[]]', ['1']],
            // MySQL subscriptions never match an object, so a rejected one serves no group.
            'rejected object' => ['{"a":["1","2"]}', []],
            'rejected object with exact ID' => ['{"a":"1","b":["2","3"]}', []],
            'string scalar' => ['"1"', ['1']],
            'zero-padded scalar' => ['"01"', []],
            'padded scalar' => ['" 1"', []],
            'decimal scalar' => ['"1.0"', []],
            'integer scalar' => ['1', []],
            'boolean scalar' => ['true', []],
            // More bound values than elements failed on every database.
            'deep multi-value nested' => ['[[["1","2"]]]', []],
            'mixed deep nested' => ['["1",[["2","3"]]]', ['1']],
            // Plans accept any integer group_id, and subscriptions match its decimal form.
            'zero and negative IDs' => ['["0","-2",["3","4"],"-0","+1"]', ['0', '-2']],
            // Full sync skipped empty() values: no query, no user hook.
            'zero string scalar' => ['"0"', []],
            'zero integer scalar' => ['0', []],
            'empty string scalar' => ['""', []],
            'false scalar' => ['false', []],
        ];
    }

    /**
     * Fewer bound values than elements: SQLite ran full sync with shifted
     * bindings, served nobody and still ran the user hook, so these return a
     * value that matches no user; other databases failed.
     */
    public static function shortBindings(): array
    {
        return [
            'empty nested' => ['["1",[[]]]', [null], ['1']],
            'only empty nested' => ['[[[]]]', [null], []],
            'empty nested between' => ['["1",[[]],"2"]', [null], ['1', '2']],
            'object with empty nested' => ['{"a":"1","b":[[]]}', [null], []],
        ];
    }

    public function test_group_value_keys_keep_every_value_and_type_apart(): void
    {
        // json_encode() gives false for INF and -INF, and false as an array
        // key is 0, the key of the integer 0.
        $values = [0, '0', 0.0, false, 1, '1', 1.0, true, INF, -INF, '', ' 1', '01'];
        $keys = array_map([Server::class, 'groupValueKey'], $values);
        $this->assertCount(count($values), array_unique($keys));
        $this->assertContainsOnly('string', $keys);
    }

    /**
     * v2_user.group_id is a 32-bit integer on MySQL and PostgreSQL and 64-bit
     * on SQLite; recovery keeps only IDs the column can hold.
     */
    public static function idRange(): array
    {
        return [
            'list' => ['["2147483647","2147483648",["1","2"],"-2147483648","-2147483649"]',
                ['2147483647', '2147483648', '-2147483648', '-2147483649'], ['2147483647', '-2147483648']],
            'scalar' => ['"2147483648"', ['2147483648'], []],
        ];
    }

    #[DataProvider('idRange')]
    public function test_recovery_keeps_ids_the_user_column_can_hold(string $stored, array $sqlite, array $other): void
    {
        $server = (new Server())->setRawAttributes(['group_ids' => $stored]);
        $expected = $server->getConnection()->getDriverName() === 'sqlite' ? $sqlite : $other;
        $this->assertSame($expected, $server->syncGroupIds());
    }

    #[DataProvider('shortBindings')]
    public function test_short_bindings_follow_what_full_sync_did_on_this_database(string $stored, array $sqlite, array $other): void
    {
        $server = (new Server())->setRawAttributes(['group_ids' => $stored]);
        $expected = $server->getConnection()->getDriverName() === 'sqlite' ? $sqlite : $other;
        $this->assertSame($expected, $server->syncGroupIds());
    }

    #[DataProvider('shapes')]
    public function test_sync_group_ids(string $stored, array $expected): void
    {
        $server = (new Server())->setRawAttributes(['group_ids' => $stored]);
        $this->assertSame($expected, $server->syncGroupIds());
    }
}
