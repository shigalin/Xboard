<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommissionLog;
use App\Models\Order;
use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\Stat;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\Ticket;
use App\Models\User;
use App\Utils\Helper;
use App\Services\StatisticalService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class StatController extends Controller
{
    private $service;
    public function __construct(StatisticalService $service)
    {
        $this->service = $service;
    }
    public function getOverride(Request $request)
    {
        // 获取在线节点数
        $onlineNodes = Server::all()->filter(function ($server) {
            return !!$server->is_online;
        })->count();
        // 获取在线设备数和在线用户数
        $onlineDevices = User::where('t', '>=', time() - 600)
            ->sum('online_count');
        $onlineUsers = User::where('t', '>=', time() - 600)
            ->count();

        // 获取今日流量统计
        $todayStart = strtotime('today');
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthStart = strtotime(date('Y-m-1'));
        $monthTraffic = StatServer::where('record_at', '>=', $monthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        return [
            'data' => [
                'month_income' => Order::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'month_register_total' => User::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->count(),
                'ticket_pending_total' => Ticket::where('status', 0)
                    ->count(),
                'commission_pending_total' => Order::where('commission_status', 0)
                    ->where('invite_user_id', '!=', NULL)
                    ->whereNotIn('status', [0, 2])
                    ->where('commission_balance', '>', 0)
                    ->count(),
                'day_income' => Order::where('created_at', '>=', strtotime(date('Y-m-d')))
                    ->where('created_at', '<', time())
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'last_month_income' => Order::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->whereNotIn('status', [0, 2])
                    ->sum('total_amount'),
                'commission_month_payout' => CommissionLog::where('created_at', '>=', strtotime(date('Y-m-1')))
                    ->where('created_at', '<', time())
                    ->sum('get_amount'),
                'commission_last_month_payout' => CommissionLog::where('created_at', '>=', strtotime('-1 month', strtotime(date('Y-m-1'))))
                    ->where('created_at', '<', strtotime(date('Y-m-1')))
                    ->sum('get_amount'),
                // 新增统计数据
                'online_nodes' => $onlineNodes,
                'online_devices' => $onlineDevices,
                'online_users' => $onlineUsers,
                'today_traffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'month_traffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'total_traffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * Get order statistics with filtering and pagination
     *
     * @param Request $request
     * @return array
     */
    public function getOrder(Request $request)
    {
        $request->validate([
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d',
            'type' => 'nullable|in:paid_total,paid_count,commission_total,commission_count',
        ]);

        $query = Stat::where('record_type', 'd');

        // Apply date filters
        if ($request->input('start_date')) {
            $query->where('record_at', '>=', strtotime($request->input('start_date')));
        }
        if ($request->input('end_date')) {
            $query->where('record_at', '<=', strtotime($request->input('end_date') . ' 23:59:59'));
        }

        $statistics = $query->orderBy('record_at', 'DESC')
            ->get();

        $summary = [
            'paid_total' => 0,
            'paid_count' => 0,
            'commission_total' => 0,
            'commission_count' => 0,
            'start_date' => $request->input('start_date', date('Y-m-d', $statistics->last()?->record_at)),
            'end_date' => $request->input('end_date', date('Y-m-d', $statistics->first()?->record_at)),
            'avg_paid_amount' => 0,
            'avg_commission_amount' => 0
        ];

        $dailyStats = [];
        foreach ($statistics as $statistic) {
            $date = date('Y-m-d', $statistic['record_at']);

            // Update summary
            $summary['paid_total'] += $statistic['paid_total'];
            $summary['paid_count'] += $statistic['paid_count'];
            $summary['commission_total'] += $statistic['commission_total'];
            $summary['commission_count'] += $statistic['commission_count'];

            // Calculate daily stats
            $dailyData = [
                'date' => $date,
                'paid_total' => $statistic['paid_total'],
                'paid_count' => $statistic['paid_count'],
                'commission_total' => $statistic['commission_total'],
                'commission_count' => $statistic['commission_count'],
                'avg_order_amount' => $statistic['paid_count'] > 0 ? round($statistic['paid_total'] / $statistic['paid_count'], 2) : 0,
                'avg_commission_amount' => $statistic['commission_count'] > 0 ? round($statistic['commission_total'] / $statistic['commission_count'], 2) : 0
            ];

            if ($request->input('type')) {
                $dailyStats[] = [
                    'date' => $date,
                    'value' => $statistic[$request->input('type')],
                    'type' => $this->getTypeLabel($request->input('type'))
                ];
            } else {
                $dailyStats[] = $dailyData;
            }
        }

        // Calculate averages for summary
        if ($summary['paid_count'] > 0) {
            $summary['avg_paid_amount'] = round($summary['paid_total'] / $summary['paid_count'], 2);
        }
        if ($summary['commission_count'] > 0) {
            $summary['avg_commission_amount'] = round($summary['commission_total'] / $summary['commission_count'], 2);
        }

        // Add percentage calculations to summary
        $summary['commission_rate'] = $summary['paid_total'] > 0
            ? round(($summary['commission_total'] / $summary['paid_total']) * 100, 2)
            : 0;

        return [
            'code' => 0,
            'message' => 'success',
            'data' => [
                'list' => array_reverse($dailyStats),
                'summary' => $summary,
            ]
        ];
    }

    public function getUsage(Request $request)
    {
        $today = Carbon::today();
        // reset:log can delete the boundary day's midnight after it starts.
        // PHP month overflow can move today's cutoff backwards; keep the last
        // cutoff of the previous month so deleted dates stay unavailable.
        $previousMonthEnd = $today->copy()->startOfMonth()->subDay();
        $cleanupCutoff = max(
            strtotime('-2 month', $today->timestamp),
            strtotime('-2 month', $previousMonthEnd->timestamp)
        );
        $availableFrom = Carbon::createFromTimestamp($cleanupCutoff, $today->getTimezone())
            ->startOfDay()->addDay()->format('Y-m-d');
        $request->mergeIfMissing([
            'start_date' => $today->copy()->subDays(29)->format('Y-m-d'),
            'end_date' => $today->format('Y-m-d'),
        ]);
        $params = $request->validate([
            'start_date' => 'required|date_format:Y-m-d|after_or_equal:' . $availableFrom,
            'end_date' => 'required|date_format:Y-m-d|after_or_equal:start_date|before_or_equal:' . $today->format('Y-m-d'),
        ], [
            'start_date.after_or_equal' => '使用记录仅保留近两个月，开始日期不能早于 ' . $availableFrom,
            'end_date.before_or_equal' => '结束日期不能晚于今天',
        ]);

        $start = Carbon::parse($params['start_date'])->startOfDay();
        $end = Carbon::parse($params['end_date'])->startOfDay();
        $query = StatUser::where('record_type', 'd')
            ->where('record_at', '>=', $start->timestamp)
            ->where('record_at', '<', $end->copy()->addDay()->timestamp)
            ->whereNotIn('user_id', User::where('is_admin', true)->select('id'))
            ->whereRaw('u + d > 0');

        $statistics = (clone $query)
            ->selectRaw('record_at, COUNT(DISTINCT user_id) as active_users, SUM(u + d) as traffic')
            ->groupBy('record_at')
            ->get()
            ->keyBy('record_at');

        $dailyStats = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $statistic = $statistics->get($day->timestamp);
            $dailyStats[] = [
                'date' => $day->format('Y-m-d'),
                'active_users' => (int) ($statistic?->active_users ?? 0),
                'traffic' => (int) ($statistic?->traffic ?? 0),
            ];
        }

        $traffic = array_sum(array_column($dailyStats, 'traffic'));
        $days = count($dailyStats);

        return $this->success([
            'list' => $dailyStats,
            'summary' => [
                'start_date' => $params['start_date'],
                'end_date' => $params['end_date'],
                'available_from' => $availableFrom,
                'active_users' => (clone $query)->distinct()->count('user_id'),
                'traffic' => $traffic,
                'avg_daily_users' => round(array_sum(array_column($dailyStats, 'active_users')) / $days, 2),
                'avg_daily_traffic' => $traffic / $days,
            ],
        ]);
    }

    /**
     * Get human readable label for statistic type
     *
     * @param string $type
     * @return string
     */
    private function getTypeLabel(string $type): string
    {
        return match ($type) {
            'paid_total' => '收款金额',
            'paid_count' => '收款笔数',
            'commission_total' => '佣金金额(已发放)',
            'commission_count' => '佣金笔数(已发放)',
            default => $type
        };
    }

    // 获取当日实时流量排行
    public function getServerLastRank()
    {
        $data = $this->service->getServerRank();
        return $this->success(data: $data);
    }
    // 获取昨日节点流量排行
    public function getServerYesterdayRank()
    {
        $data = $this->service->getServerRank('yesterday');
        return $this->success($data);
    }

    public function getStatUser(Request $request)
    {
        $request->validate([
            'user_id' => 'required|integer'
        ]);

        $pageSize = $request->input('pageSize', 10);
        $records = StatUser::orderBy('record_at', 'DESC')
            ->where('user_id', $request->input('user_id'))
            ->paginate($pageSize);

        $data = $records->items();
        return [
            'data' => $data,
            'total' => $records->total(),
        ];
    }

    public function getStatRecord(Request $request)
    {
        return [
            'data' => $this->service->getStatRecord($request->input('type'))
        ];
    }

    /**
     * Get comprehensive statistics data including income, users, and growth rates
     */
    public function getStats()
    {
        $currentMonthStart = strtotime(date('Y-m-01'));
        $lastMonthStart = strtotime('-1 month', $currentMonthStart);
        $twoMonthsAgoStart = strtotime('-2 month', $currentMonthStart);

        // Today's start timestamp
        $todayStart = strtotime('today');
        $yesterdayStart = strtotime('-1 day', $todayStart);

        // 获取在线节点数
        $onlineNodes = Server::all()->filter(function ($server) {
            return !!$server->is_online;
        })->count();

        // 获取在线设备数和在线用户数
        $onlineDevices = User::where('t', '>=', time() - 600)
            ->sum('online_count');
        $onlineUsers = User::where('t', '>=', time() - 600)
            ->count();

        // 获取今日流量统计
        $todayTraffic = StatServer::where('record_at', '>=', $todayStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取本月流量统计
        $monthTraffic = StatServer::where('record_at', '>=', $currentMonthStart)
            ->where('record_at', '<', time())
            ->selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // 获取总流量统计
        $totalTraffic = StatServer::selectRaw('SUM(u) as upload, SUM(d) as download, SUM(u + d) as total')
            ->first();

        // Today's income
        $todayIncome = Order::where('created_at', '>=', $todayStart)
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Yesterday's income for day growth calculation
        $yesterdayIncome = Order::where('created_at', '>=', $yesterdayStart)
            ->where('created_at', '<', $todayStart)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Current month income
        $currentMonthIncome = Order::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Last month income
        $lastMonthIncome = Order::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Last month commission payout
        $lastMonthCommissionPayout = CommissionLog::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->sum('get_amount');

        // Current month commission payout
        $currentMonthCommissionPayout = CommissionLog::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->sum('get_amount');

        // Current month new users
        $currentMonthNewUsers = User::where('created_at', '>=', $currentMonthStart)
            ->where('created_at', '<', time())
            ->count();

        // Total users
        $totalUsers = User::count();

        // Active users (users with valid subscription)
        $activeUsers = User::where(function ($query) {
            $query->where('expired_at', '>=', time())
                ->orWhere('expired_at', NULL);
        })->count();

        // Previous month income for growth calculation
        $twoMonthsAgoIncome = Order::where('created_at', '>=', $twoMonthsAgoStart)
            ->where('created_at', '<', $lastMonthStart)
            ->whereNotIn('status', [0, 2])
            ->sum('total_amount');

        // Previous month commission for growth calculation
        $twoMonthsAgoCommission = CommissionLog::where('created_at', '>=', $twoMonthsAgoStart)
            ->where('created_at', '<', $lastMonthStart)
            ->sum('get_amount');

        // Previous month users for growth calculation
        $lastMonthNewUsers = User::where('created_at', '>=', $lastMonthStart)
            ->where('created_at', '<', $currentMonthStart)
            ->count();

        // Calculate growth rates
        $monthIncomeGrowth = $lastMonthIncome > 0 ? round(($currentMonthIncome - $lastMonthIncome) / $lastMonthIncome * 100, 1) : 0;
        $lastMonthIncomeGrowth = $twoMonthsAgoIncome > 0 ? round(($lastMonthIncome - $twoMonthsAgoIncome) / $twoMonthsAgoIncome * 100, 1) : 0;
        $commissionGrowth = $twoMonthsAgoCommission > 0 ? round(($lastMonthCommissionPayout - $twoMonthsAgoCommission) / $twoMonthsAgoCommission * 100, 1) : 0;
        $userGrowth = $lastMonthNewUsers > 0 ? round(($currentMonthNewUsers - $lastMonthNewUsers) / $lastMonthNewUsers * 100, 1) : 0;
        $dayIncomeGrowth = $yesterdayIncome > 0 ? round(($todayIncome - $yesterdayIncome) / $yesterdayIncome * 100, 1) : 0;

        // 获取待处理工单和佣金数据
        $ticketPendingTotal = Ticket::where('status', 0)->count();
        $commissionPendingTotal = Order::where('commission_status', 0)
            ->where('invite_user_id', '!=', NULL)
            ->whereIn('status', [Order::STATUS_COMPLETED])
            ->where('commission_balance', '>', 0)
            ->count();

        return [
            'data' => [
                // 收入相关
                'todayIncome' => $todayIncome,
                'dayIncomeGrowth' => $dayIncomeGrowth,
                'currentMonthIncome' => $currentMonthIncome,
                'lastMonthIncome' => $lastMonthIncome,
                'monthIncomeGrowth' => $monthIncomeGrowth,
                'lastMonthIncomeGrowth' => $lastMonthIncomeGrowth,

                // 佣金相关
                'currentMonthCommissionPayout' => $currentMonthCommissionPayout,
                'lastMonthCommissionPayout' => $lastMonthCommissionPayout,
                'commissionGrowth' => $commissionGrowth,
                'commissionPendingTotal' => $commissionPendingTotal,

                // 用户相关
                'currentMonthNewUsers' => $currentMonthNewUsers,
                'totalUsers' => $totalUsers,
                'activeUsers' => $activeUsers,
                'userGrowth' => $userGrowth,
                'onlineUsers' => $onlineUsers,
                'onlineDevices' => $onlineDevices,

                // 工单相关
                'ticketPendingTotal' => $ticketPendingTotal,

                // 节点相关
                'onlineNodes' => $onlineNodes,

                // 流量统计
                'todayTraffic' => [
                    'upload' => $todayTraffic->upload ?? 0,
                    'download' => $todayTraffic->download ?? 0,
                    'total' => $todayTraffic->total ?? 0
                ],
                'monthTraffic' => [
                    'upload' => $monthTraffic->upload ?? 0,
                    'download' => $monthTraffic->download ?? 0,
                    'total' => $monthTraffic->total ?? 0
                ],
                'totalTraffic' => [
                    'upload' => $totalTraffic->upload ?? 0,
                    'download' => $totalTraffic->download ?? 0,
                    'total' => $totalTraffic->total ?? 0
                ]
            ]
        ];
    }

    /**
     * The keys of $values (stored group values keyed by their JSON) that
     * match at least one user's group_id. One statement compares up to
     * Server::GROUP_VALUE_COLUMNS values with the IN full sync runs, in a
     * single pass over the user table. Call it inside Helper::readSnapshot()
     * so all chunks read the same snapshot. If the database rejects a
     * value, every value counts as matched, which keeps the nodes in the
     * ranking.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    private static function groupValuesWithUsers(array $values): array
    {
        $populated = [];
        // One column per value, in chunks that stay under every database's
        // result column limit.
        foreach (array_chunk($values, Server::GROUP_VALUE_COLUMNS, true) as $chunk) {
            $keys = array_keys($chunk);
            $columns = array_map(fn ($index) => "MAX(CASE WHEN group_id IN (?) THEN 1 ELSE 0 END) AS v{$index}",
                array_keys($keys));
            $row = Server::compareGroupValues(fn () => User::toBase()
                ->selectRaw(implode(', ', $columns), array_values($chunk))
                ->first(), false);
            foreach ($keys as $index => $key) {
                if ($row === false || (int) ($row->{"v{$index}"} ?? 0) === 1) {
                    $populated[] = $key;
                }
            }
        }
        return $populated;
    }

    /**
     * Get traffic ranking data for nodes or users
     * 
     * @param Request $request
     * @return array
     */
    public function getTrafficRank(Request $request)
    {
        $request->validate([
            'type' => 'required|in:node,user',
            'start_time' => 'nullable|integer|min:1000000000|max:9999999999',
            'end_time' => 'nullable|integer|min:1000000000|max:9999999999'
        ]);

        $type = $request->input('type');
        $startDate = $request->input('start_time', strtotime('-7 days'));
        $endDate = $request->input('end_time', time());
        $previousStartDate = $startDate - ($endDate - $startDate);
        $previousEndDate = $startDate;

        if ($type === 'node') {
            // The admin group names, the traffic totals and the group and user
            // reads, chunks included, share one snapshot: a group renamed or a
            // user changing group between two reads is seen one way only.
            [$ranked, $adminOnly] = Helper::readSnapshot(function () use ($startDate, $endDate) {
                // Nodes whose permission groups are all named "admin" are
                // admin-only. Match the name in PHP: accent-insensitive MySQL
                // collations would also match names such as "Ádmin" in SQL.
                $adminGroupIds = ServerGroup::get(['id', 'name'])
                    ->filter(fn ($group) => strtolower($group->name) === 'admin')
                    ->map(fn ($group) => (int) $group->id)
                    ->all();
                // Get node traffic data. With admin groups, rank every node
                // with traffic in the period and leave out the admin-only ones.
                $ranked = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                    ->where('record_at', '>=', $startDate)
                    ->where('record_at', '<=', $endDate)
                    ->groupBy('server_id')
                    ->orderBy('value', 'DESC')
                    ->when($adminGroupIds === [], fn ($query) => $query->limit(10))
                    ->get();
                if ($adminGroupIds === []) {
                    return [$ranked, []];
                }

                // A node is admin-only when the groups full sync serves it are
                // all admin groups, and there is at least one. Values are
                // compared by the database exactly as full sync compares them.
                // A value with no group row serves the users still in that
                // group (a plan's group_id need not have a row), so it disqualifies the
                // node only if such users exist. A value the database cannot
                // compare makes full sync fail, so the node serves no one and
                // stays in the ranking; it affects only its own node. Deleted
                // nodes cannot be classified and stay too.
                $adminOnly = [];
                $servers = Server::whereIntegerInRaw('id', $ranked->pluck('id')->all())
                    ->get(['id', 'group_ids'])->keyBy('id');
                $groupCache = [];
                Server::primeGroupValueCache($servers, $groupCache);
                $candidates = [];
                $rowless = [];
                foreach ($ranked as $data) {
                    $server = $servers->get($data->id);
                    if ($server === null) {
                        continue;
                    }
                    $servesAdmins = false;
                    $values = [];
                    foreach ($server->groupValueMatches($groupCache) as $match) {
                        if (!$match['comparable']
                            || ($match['group'] !== null && !in_array($match['group'], $adminGroupIds, true))) {
                            continue 2;
                        }
                        if ($match['group'] !== null) {
                            $servesAdmins = true;
                        } else {
                            $values[Server::groupValueKey($match['value'])] = $match['value'];
                        }
                    }
                    if ($servesAdmins) {
                        $candidates[$data->id] = array_keys($values);
                        $rowless += $values;
                    }
                }
                $populated = self::groupValuesWithUsers($rowless);
                foreach ($candidates as $id => $keys) {
                    if (array_intersect($keys, $populated) === []) {
                        $adminOnly[$id] = true;
                    }
                }
                return [$ranked, $adminOnly];
            });
            $currentData = $ranked->reject(fn ($data) => isset($adminOnly[$data->id]))->take(10)->values();

            // Get previous period data for comparison
            $previousData = StatServer::selectRaw('server_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('server_id', $currentData->pluck('id'))
                ->groupBy('server_id')
                ->get()
                ->keyBy('id');

        } else {
            // Get user traffic data
            $currentData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->whereNotIn('user_id', User::where('is_admin', true)->select('id'))
                ->where('record_at', '>=', $startDate)
                ->where('record_at', '<=', $endDate)
                ->groupBy('user_id')
                ->orderBy('value', 'DESC')
                ->limit(10)
                ->get();

            // Get previous period data for comparison
            $previousData = StatUser::selectRaw('user_id as id, SUM(u + d) as value')
                ->where('record_at', '>=', $previousStartDate)
                ->where('record_at', '<', $previousEndDate)
                ->whereIn('user_id', $currentData->pluck('id'))
                ->groupBy('user_id')
                ->get()
                ->keyBy('id');
        }

        $result = [];
        $ids = $currentData->pluck('id');
        $names = $type === 'node'
            ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        foreach ($currentData as $data) {
            $previousValue = isset($previousData[$data->id]) ? $previousData[$data->id]->value : 0;
            $change = $previousValue > 0 ? round(($data->value - $previousValue) / $previousValue * 100, 1) : 0;

            $result[] = [
                'id' => (string) $data->id,
                'name' => $names[$data->id] ?? ($type === 'node' ? "Node {$data->id}" : "User {$data->id}"),
                'value' => $data->value,
                'previousValue' => $previousValue,
                'change' => $change,
                'timestamp' => date('c', $endDate)
            ];
        }

        return [
            'timestamp' => date('c'),
            'data' => $result
        ];
    }
}
