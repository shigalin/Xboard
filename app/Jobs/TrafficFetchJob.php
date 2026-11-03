<?php

namespace App\Jobs;

use App\Models\User;
use App\Models\UserServerTraffic;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;

class TrafficFetchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $timestamp;
    public $tries = 1;
    public $timeout = 20;

    public function __construct(array $server, array $data, $protocol, int $timestamp)
    {
        $this->onQueue('traffic_fetch');
        $this->server = $server;
        $this->data = $data;
        $this->protocol = $protocol;
        $this->timestamp = $timestamp;
    }

    public function handle(): void
    {
        $userIds = array_keys($this->data);
        $connection = DB::connection();
        // SQLite 3.35 added UPDATE ... RETURNING.
        $returning = $connection->getDriverName() === 'sqlite'
            && version_compare($connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), '3.35.0', '>=');

        foreach ($this->data as $uid => $v) {
            DB::transaction(function () use ($uid, $v, $returning) {
                // Serialize accounting, detail writes and resets on the same user.
                $counters = $this->incrementCounters($uid, $v, $returning);
                if (!$counters || ($v[0] == 0 && $v[1] == 0)) {
                    return;
                }

                [$before, $after] = $counters;
                $increments = [
                    'u' => $v[0],
                    'd' => $v[1],
                    // Read the actual counter delta to preserve each database's
                    // existing rounding of fractional node rates.
                    'billed_u' => $after->u - $before->u,
                    'billed_d' => $after->d - $before->d,
                ];
                $extra = ['server_name' => $this->server['name']];
                // A report normally changes its row, so no affected row means the
                // user's first report from this node in the current cycle.
                $updated = UserServerTraffic::where('user_id', $uid)->where('server_id', $this->server['id'])
                    ->toBase()->incrementEach($increments, $extra);
                if (!$updated) {
                    $record = UserServerTraffic::firstOrCreate([
                        'user_id' => $uid,
                        'server_id' => $this->server['id'],
                    ], $extra);
                    UserServerTraffic::whereKey($record->id)->incrementEach($increments, $extra);
                }
            }, 3);
        }

        if (!empty($userIds)) {
            Redis::sadd('traffic:pending_check', ...$userIds);
        }
    }

    /**
     * Add the rated traffic to the user's counters and return them before and
     * after, or null if the user no longer exists. Both paths start with a write,
     * so SQLite waits for its writer lock instead of failing to upgrade a read
     * transaction under concurrent reports.
     */
    private function incrementCounters(int $uid, array $v, bool $returning): ?array
    {
        $u = $v[0] * $this->server['rate'];
        $d = $v[1] * $this->server['rate'];
        if ($returning) {
            // Reading through RETURNING holds SQLite's database-wide writer lock
            // for two statements instead of four.
            $before = DB::selectOne('update v2_user set u = u where id = ? returning u, d', [$uid], false);
            if (!$before) {
                return null;
            }
            return [$before, DB::selectOne(
                'update v2_user set u = u + ?, d = d + ?, t = ? where id = ? returning u, d',
                [$u, $d, time(), $uid],
                false
            )];
        }

        // The no-op write also lets MySQL lock the row without logging an extra
        // binlog row image.
        User::whereKey($uid)->toBase()->update(['u' => DB::raw('u')]);
        $before = User::whereKey($uid)->toBase()->lockForUpdate()->first(['u', 'd']);
        if (!$before) {
            return null;
        }
        User::whereKey($uid)->toBase()->incrementEach(['u' => $u, 'd' => $d], ['t' => time()]);
        return [$before, User::whereKey($uid)->toBase()->first(['u', 'd'])];
    }
}
