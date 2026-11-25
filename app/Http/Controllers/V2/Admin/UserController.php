<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Services\AuthService;
use App\Services\DeviceStateService;
use App\Services\NodeSyncService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class UserController extends Controller
{
    use QueryOperators;

    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user)
            return $this->fail([400202, '用户不存在']);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        $result = $user->save();

        if ($result) {
            HookManager::call('admin.user.secret.reset', [
                'user' => $user,
                'request' => $request,
            ]);
        }

        return $this->success($result);
    }

    // Apply filters and sorts to the query builder.
    private function applyFiltersAndSorts(Request $request, Builder|QueryBuilder $builder, ?int $recordAt = null): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder, $recordAt);
    }

    // Apply filters to the query builder.
    private function applyFilters(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];
            $logic = strtolower($filter['logic'] ?? 'and');

            if ($logic === 'or') {
                $builder->orWhere(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            } else {
                $builder->where(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            }
        });
    }

    // Build one filter query condition.
    private function buildFilterQuery(Builder|QueryBuilder $query, string $field, mixed $value): void
    {
        // 处理关联查询
        if (str_contains($field, '.')) {
            if (!method_exists($query, 'whereHas')) {
                return;
            }
            [$relation, $relationField] = explode('.', $field);
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if ($relationField === 'email' && !is_array($value)) {
                    $this->applyEmailFilter($q, (string) $value);
                    return;
                }
                if (is_array($value)) {
                    $values = $relationField === 'email'
                        ? array_map(static fn ($value) => User::normalizeEmail((string) $value), $value)
                        : $value;
                    $q->whereIn($relationField, $values);
                } elseif (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        if ($field === 'email' && !is_array($value)) {
            $this->applyEmailFilter($query, (string) $value);
            return;
        }

        // 处理数组值的 'in' 操作
        if (is_array($value)) {
            $values = $field === 'email'
                ? array_map(static fn ($value) => User::normalizeEmail((string) $value), $value)
                : $value;
            $query->whereIn($field === 'group_ids' ? 'group_id' : $field, $values);
            return;
        }

        // 处理基于运算符的过滤
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 转换数字字符串为适当的类型
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // 处理计算字段
        $queryField = match ($field) {
            'total_used' => DB::raw('(u + d)'),
            default => $field
        };

        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    private function applyEmailFilter(Builder|QueryBuilder $query, string $value): void
    {
        [$operator, $keyword] = str_contains($value, ':') ? explode(':', $value, 2) : ['like', $value];
        $queryOperator = $this->getQueryOperator($operator);
        if (in_array($queryOperator, ['like', 'not like'], true)) {
            User::applyEmailSearch($query, $keyword, $queryOperator === 'not like');
            return;
        }
        $this->applyQueryCondition($query, 'email', $operator, User::normalizeEmail($keyword));
    }

    // Apply sorting rules to the query builder.
    private function applySorting(Request $request, Builder|QueryBuilder $builder, ?int $recordAt): void
    {
        $sorts = $request->input('sort', []);
        // The paginated list sends an array. Filtered actions send one field
        // with a separate direction.
        if (is_string($sorts)) {
            $sorts = $sorts === '' ? [] : [['id' => $sorts, 'desc' => $request->input('sort_type') !== 'ASC']];
        }

        foreach (collect($sorts) as $sort) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            if ($field === 'today_traffic') {
                $query = $builder instanceof Builder ? $builder->getQuery() : $builder;
                $userIdColumn = $this->trafficSortUserId($query);
                $builder->orderBy($this->todayTrafficTotal($userIdColumn, $recordAt ??= Date::today()->timestamp), $direction);
                continue;
            }
            $builder->orderBy($field, $direction);
        }
    }

    private function trafficSortUserId(QueryBuilder $query): string|Expression
    {
        $from = $query->from;
        if (is_string($from)) {
            return (preg_split('/\s+as\s+/i', $from, 2)[1] ?? $from) . '.id';
        }
        if ($from instanceof Expression) {
            $grammar = $query->getGrammar();
            $source = $from->getValue($grammar);
            // fromSub() has already quoted and prefixed its alias. Preserve
            // that token so the correlation resolves even when a join has id.
            $alias = '/(?:\s+as\s+|\)\s+)(`(?:``|[^`])+`|"(?:""|[^"])+"|\[[^\]]+\]|[a-z_][\w$]*)\s*\z/i';
            if (is_string($source) && preg_match($alias, $source, $parts)) {
                return DB::raw($parts[1] . '.' . $grammar->wrap('id'));
            }
        }

        return 'id';
    }

    // Resolve bulk operation scope and normalize user_ids.
    private function resolveScope(Request $request): array
    {
        $scope = $request->input('scope');
        $userIds = $request->input('user_ids');

        $hasSelection = is_array($userIds) && count(array_filter($userIds, static fn($v) => is_numeric($v))) > 0;
        $hasFilter = $request->has('filter') && !empty($request->input('filter'));

        if (!in_array($scope, ['selected', 'filtered', 'all'], true)) {
            if ($hasSelection) {
                $scope = 'selected';
            } elseif ($hasFilter) {
                $scope = 'filtered';
            } else {
                $scope = 'all';
            }
        }

        $normalizedIds = [];
        if ($scope === 'selected') {
            $normalizedIds = is_array($userIds) ? $userIds : [];
            $normalizedIds = array_values(array_unique(array_map(static function ($v) {
                return is_numeric($v) ? (int) $v : null;
            }, $normalizedIds)));
            $normalizedIds = array_values(array_filter($normalizedIds, static fn($v) => is_int($v)));
        }

        return [
            'scope' => $scope,
            'user_ids' => $normalizedIds,
        ];
    }

    // Fetch paginated user list (filters + sorting).
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::query()
            ->with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select((new User())->getTable() . '.*')
            ->selectRaw('(u + d) as total_used');

        $userModel = HookManager::filter('admin.user.fetch.query', $userModel, $request);

        $recordAt = Date::today()->timestamp;
        $this->applyFiltersAndSorts($request, $userModel, $recordAt);

        // Read node counts and today's billed bytes, including deleted nodes,
        // outside the plugin-adjustable query but in the same snapshot.
        [$users, $traffic] = Helper::readSnapshot(function () use ($userModel, $pageSize, $current, $recordAt) {
            $users = $userModel->orderBy('id', 'desc')
                ->paginate($pageSize, ['*'], 'page', $current);
            $userIds = $users->getCollection()->pluck('id')->all();
            $traffic = $this->nodeTrafficSummary($userIds, $recordAt)->get()->keyBy('user_id');
            return [$users, $traffic];
        });

        $users->getCollection()->transform(function ($user) use ($traffic): array {
            $usage = $traffic->get($user->id);
            $user->setAttribute('used_node_count', (int) ($usage?->used_node_count ?? 0));
            $user->setAttribute('today_traffic', ($usage?->today_traffic ?? 0) + 0);
            $user->setAttribute('today_node_count', (int) ($usage?->today_node_count ?? 0));
            return self::transformUserData($user);
        });

        return $this->paginate($users);
    }

    private function nodeTrafficSummary(array $userIds, int $recordAt): QueryBuilder
    {
        // Inline integer IDs because pageSize can exceed the parameter limit.
        $table = (new UserServerTraffic())->getTable();
        $summary = DB::table($table . ' as traffic')->whereIntegerInRaw('traffic.user_id', $userIds)
            ->groupBy('traffic.user_id')
            ->selectRaw('traffic.user_id, SUM(CASE WHEN in_cycle THEN 1 ELSE 0 END) as used_node_count')
            ->selectRaw('SUM(CASE WHEN daily_record_at = ? THEN 1 ELSE 0 END) as today_node_count', [$recordAt]);

        if ($summary->getConnection()->getDriverName() !== 'sqlite') {
            return $summary->selectRaw(
                'SUM(CASE WHEN daily_record_at = ? THEN daily_billed_u + daily_billed_d ELSE 0 END) as today_traffic',
                [$recordAt],
            );
        }

        return $summary->selectSub($this->todayTrafficTotal('traffic.user_id', $recordAt), 'today_traffic');
    }

    private function todayTrafficTotal(string|Expression $userIdColumn, int $recordAt): QueryBuilder
    {
        $table = (new UserServerTraffic())->getTable();
        // Keep the inner table's own id out of the outer-column lookup.
        $dailyBytes = DB::table($table)
            ->where('daily_record_at', $recordAt)
            ->select('user_id', 'server_id')
            ->selectRaw('daily_billed_u + daily_billed_d as billed');
        $bytes = DB::query()->fromSub($dailyBytes, 'daily')
            ->whereColumn('daily.user_id', $userIdColumn);
        if ($bytes->getConnection()->getDriverName() !== 'sqlite') {
            return $bytes->selectRaw('COALESCE(SUM(daily.billed), 0)');
        }

        // Preserve the addition order of SQLite's fractional billed bytes.
        return DB::query()->fromSub($bytes->select('daily.billed')->orderBy('daily.server_id'), 'ordered_bytes')
            ->selectRaw('COALESCE(SUM(billed), 0)');
    }

    // Transform user fields for API response.
    public static function transformUserData(User $user): array
    {
        $model = $user;
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => '用户ID不能为空'
        ]);
        $user = User::find($request->input('id'))->load('invite_user');
        $user = HookManager::filter('admin.user.detail', $user, $request);
        return $this->success($user);
    }

    public function getOnlineIPs(Request $request, DeviceStateService $devices)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1',
        ]);
        if (!User::whereKey($params['id'])->exists()) {
            return $this->fail([400202, '用户不存在']);
        }

        return $this->success($devices->getOnlineIPs((int) $params['id']));
    }

    public function getNodeTraffic(Request $request)
    {
        $params = $request->validate([
            'id' => 'required|integer|min:1',
            'period' => 'sometimes|in:cycle,today',
        ]);
        $recordAt = ($params['period'] ?? 'cycle') === 'today' ? Date::today()->timestamp : null;

        return DB::transaction(function () use ($params, $recordAt) {
            // Keep the totals and breakdown in one snapshot, including resets.
            $user = User::whereKey($params['id'])->sharedLock()->first();
            if (!$user) {
                return $this->fail([400202, '用户不存在']);
            }

            $query = $recordAt === null
                ? UserServerTraffic::inCycle()
                : UserServerTraffic::where('daily_record_at', $recordAt);
            $table = $query->getModel()->getTable();
            $prefix = $recordAt === null ? '' : 'daily_';
            $query
                ->leftJoin('v2_server as server', 'server.id', '=', $table . '.server_id')
                ->where($table . '.user_id', $user->id)
                ->select(
                    $table . '.server_id', 'server.name as current_name', 'server.id as current_id',
                    "{$table}.{$prefix}u as upload", "{$table}.{$prefix}d as download",
                )
                ->selectRaw("COALESCE({$table}.{$prefix}server_name, {$table}.server_name) as reported_name")
                ->selectRaw("COALESCE({$table}.{$prefix}created_at, {$table}.created_at) as recorded_at")
                ->selectRaw("{$table}.{$prefix}billed_u + {$table}.{$prefix}billed_d as billed");
            if ($recordAt !== null) {
                // Join the same summary used by the list in this statement so
                // the total and the displayed rows see the same snapshot.
                $query->joinSub($this->nodeTrafficSummary([$user->id], $recordAt), 'summary',
                    'summary.user_id', '=', $table . '.user_id')
                    ->addSelect('summary.today_traffic');
            }
            $records = $query->orderByDesc('billed')->orderBy('server_id')->get();
            $list = $records->map(fn ($record) => [
                'server_id' => (int) $record->server_id,
                'server_name' => $record->current_name ?? $record->reported_name,
                'deleted' => $record->current_id === null,
                'upload' => $record->upload + 0,
                'download' => $record->download + 0,
                'billed' => $record->billed + 0,
            ]);
            $attributed = $recordAt === null
                ? $list->sum('billed')
                : ($records->first()?->today_traffic ?? 0) + 0;
            $recordedFrom = $records->min('recorded_at');
            // Today's usage consists of the recorded nodes, independent of
            // the current cycle counters or any resets or manual adjustments.
            $used = $recordAt === null ? $user->u + $user->d : $attributed;

            return $this->success([
                'list' => $list,
                'summary' => [
                    'total_used' => $used,
                    'attributed_total' => $attributed,
                    'unattributed_total' => max(0, $used - $attributed),
                    'inconsistent' => $attributed > $used,
                    'recorded_from' => $recordedFrom === null ? null : (int) $recordedFrom,
                    'last_reset_at' => $recordAt === null ? $user->last_reset_at : null,
                ],
            ]);
        });
    }

    public function setInviteUser(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer|exists:App\\Models\\User,id',
            'invite_user_id' => 'nullable|integer|different:id|exists:App\\Models\\User,id',
        ]);
        return DB::transaction(function () use ($data) {
            if (DB::getDriverName() === 'sqlite') {
                User::whereKey($data['id'])->toBase()->update(['invite_user_id' => DB::raw('invite_user_id')]);
            }
            $user = User::whereKey($data['id'])->lockForUpdate()->firstOrFail();
            $parent = $data['invite_user_id'] ?? null;
            $this->validateInviteUser($user, $parent);
            $user->update(['invite_user_id' => $parent]);
            return $this->success(true);
        }, 5);
    }

    private function validateInviteUser(User $user, ?int $parent): void
    {
        $visited = [(int) $user->id];
        for ($cursor = $parent; $cursor;) {
            if (in_array((int) $cursor, $visited, true)) {
                throw ValidationException::withMessages(['invite_user_email' => '邀请关系不能形成循环']);
            }
            $visited[] = (int) $cursor;
            $ancestor = User::whereKey($cursor)->lockForUpdate()->first();
            if (!$ancestor) {
                throw ValidationException::withMessages(['invite_user_email' => '邀请用户不存在']);
            }
            $cursor = $ancestor->invite_user_id;
        }
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, '用户不存在']);
        }
        if (isset($params['email'])) {
            $existingUser = User::byEmail($params['email'])->first();
            if ($existingUser && $existingUser->id !== $user->id) {
                return $this->fail([400201, '邮箱已被使用']);
            }
        }
        // 处理密码
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        // 处理订阅计划
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                return $this->fail([400202, '订阅计划不存在']);
            }
            $params['group_id'] = $plan->group_id;
        }
        // 处理邀请用户
        if (array_key_exists('invite_user_email', $params)) {
            $inviteEmail = $params['invite_user_email'];
            unset($params['invite_user_email']);
            if ($inviteEmail === null || trim($inviteEmail) === '') {
                $params['invite_user_id'] = null;
            } else {
                $inviteUser = User::byEmail($inviteEmail)->first();
                if (!$inviteUser) {
                    return $this->fail([422, '邀请用户不存在']);
                }
                $params['invite_user_id'] = $inviteUser->id;
            }
        }

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
        }
        if (isset($params['balance'])) {
            $params['balance'] = $params['balance'] * 100;
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = $params['commission_balance'] * 100;
        }

        $params = HookManager::filter('admin.user.update.params', $params, $request, $user);

        HookManager::call('admin.user.update.before', [
            'user' => $user,
            'params' => $params,
            'request' => $request,
        ]);

        try {
            DB::transaction(function () use ($user, $params) {
                // Start with a no-op write, as TrafficFetchJob does, so SQLite
                // waits for its writer lock while nodes report traffic.
                User::whereKey($user->id)->toBase()->update(['u' => DB::raw('u')]);
                $current = User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                if (array_key_exists('invite_user_id', $params)) {
                    $this->validateInviteUser($current, $params['invite_user_id']);
                }
                // Manually replacing counters has no node attribution. Start a
                // fresh breakdown only when the submitted counters really change.
                $trafficChanged = (isset($params['u']) && $params['u'] != $current->u)
                    || (isset($params['d']) && $params['d'] != $current->d);
                foreach (['u', 'd'] as $field) {
                    $user->setAttribute($field, $current->getAttribute($field));
                }
                $user->syncOriginalAttributes(['u', 'd']);
                $user->update($params);
                if ($trafficChanged) {
                    UserServerTraffic::clearCycle($user->id);
                }
            }, 5);
        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '保存失败']);
        }

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(),
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    // Export users to CSV.
    public function dumpCSV(Request $request)
    {
        ini_set('memory_limit', '-1');
        gc_enable(); // 启用垃圾回收

        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        // 优化查询：使用with预加载plan关系，避免N+1问题
        $query = User::query()
            ->with('plan:id,name')
            ->select([
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        if ($scope === 'selected') {
            $query->whereIn('id', $userIds);
        } elseif ($scope === 'filtered') {
            $query->selectRaw('(u + d) as total_used');
            $this->applyFiltersAndSorts($request, $query);
        } // all: ignore filter/sort
        $sorted = !empty($query->getQuery()->orders);
        $query->orderBy('id', $sorted ? 'desc' : 'asc');

        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query, $sorted) {
            // 打开输出流
            $output = fopen('php://output', 'w');

            // 添加BOM标记，确保Excel正确显示中文
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // 写入CSV头部
            fputcsv($output, [
                '邮箱',
                '余额',
                '推广佣金',
                '总流量',
                '剩余流量',
                '套餐到期时间',
                '订阅计划',
                '订阅地址'
            ]);

            $writeRows = fn () => $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        $row = [
                            $user->email,
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($user->transfer_enable),
                            Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d)),
                            $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '长期有效',
                            $user->plan ? $user->plan->name : '无订阅',
                            Helper::getSubscribeUrl($user->token)
                        ];
                        fputcsv($output, $row);
                    } catch (\Exception $e) {
                        Log::error('CSV导出错误: ' . $e->getMessage(), [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        continue; // 继续处理下一条记录
                    }
                }

                // 清理内存
                gc_collect_cycles();
            });

            try {
                if ($sorted) {
                    // A report can move a user across an OFFSET boundary.
                    Helper::readSnapshot($writeRows);
                } else {
                    $writeRows();
                }
            } finally {
                fclose($output);
            }
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            // If generate_count is specified with email_prefix, generate multiple users with incremented emails
            if ($request->input('generate_count')) {
                return $this->multiGenerateWithPrefix($request);
            }
            
            // Single user generation with email_prefix
            $email = User::normalizeEmail($request->input('email_prefix') . '@' . $request->input('email_suffix'));

            if (User::byEmail($email)->exists()) {
                return $this->fail([400201, '邮箱已存在于系统中']);
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ]);

            if (!$user->save()) {
                return $this->fail([500, '生成失败']);
            }
            return $this->success(true);
        }

        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];

        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $email = User::normalizeEmail(Helper::randomChar(6) . '@' . $request->input('email_suffix'));
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }



        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $data,
        ]);
    }

    private function multiGenerateWithPrefix(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];
        $emailPrefix = $request->input('email_prefix');
        $emailSuffix = $request->input('email_suffix');
        $generateCount = $request->input('generate_count');

        // Check if any of the emails with prefix already exist
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = User::normalizeEmail($emailPrefix . '_' . $i . '@' . $emailSuffix);
            if (User::byEmail($email)->exists()) {
                return $this->fail([400201, '邮箱 ' . $email . ' 已存在于系统中']);
            }
        }

        // Generate user data for batch creation
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = User::normalizeEmail($emailPrefix . '_' . $i . '@' . $emailSuffix);
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }

        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, '生成失败']);
        }

        // 判断是否导出 CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['账号', '密码', '过期时间', 'UUID', '创建时间', '订阅地址']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 默认返回 JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? '长期有效' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => '批量生成成功',
            'data' => $data,
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        ini_set('memory_limit', '-1');
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $builder = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            $this->applyFilters($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $subject = $request->input('subject');
        $content = $request->input('content');
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = admin_setting('app_url');

        $chunkSize = 1000;

        $builder->chunk($chunkSize, function ($users) use ($subject, $content, $appName, $appUrl) {
            foreach ($users as $user) {
                $vars = [
                    'app.name' => $appName,
                    'app.url' => $appUrl,
                    'now' => now()->format('Y-m-d H:i:s'),
                    'user.id' => $user->id,
                    'user.email' => $user->email,
                    'user.uuid' => $user->uuid,
                    'user.plan_name' => $user->plan?->name ?? '',
                    'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                    'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                    'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                    'user.transfer_left' => (int) (($user->transfer_enable ?? 0) - (($user->u ?? 0) + ($user->d ?? 0))),
                ];

                $templateValue = [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $content,
                    'vars' => $vars,
                    'content_mode' => 'text',
                ];

                dispatch(new SendEmailJob([
                    'email' => $user->email,
                    'subject' => $subject,
                    'template_name' => 'notify',
                    'template_value' => $templateValue
                ], 'send_email_mass'));
            }
        });

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids不能为空']);
            }
        }

        $builder = User::query()->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // Sorting does not affect which filtered users are banned.
            $this->applyFilters($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        try {
            DB::transaction(function () use ($builder): void {
                // Acquire SQLite's writer lock before reading the user IDs,
                // so concurrent node reports cannot invalidate the snapshot.
                if (DB::getDriverName() === 'sqlite') {
                    $builder->toBase()->update(['banned' => DB::raw('banned')]);
                }
                $ids = (clone $builder)->reorder()->lockForUpdate()->pluck('id');
                foreach ($ids->chunk(500) as $chunk) {
                    // Do not reevaluate a moving filter under READ COMMITTED:
                    // the ban and token revocation must affect identical IDs.
                    User::whereKey($chunk)->update(['banned' => 1]);
                    \Laravel\Sanctum\PersonalAccessToken::where('tokenable_type', (new User())->getMorphClass())
                        ->whereIn('tokenable_id', $chunk)->delete();
                }
            });
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '处理失败']);
        }
        // Full refresh not implemented.
        return $this->success(true);
    }

    // Delete user and related data.
    public function destroy(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => '用户ID不能为空',
            'id.exists' => '用户不存在'
        ]);
        $user = User::find($request->input('id'));
        HookManager::call('admin.user.destroy.before', [
            'user' => $user,
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();
            DB::commit();

            HookManager::call('admin.user.destroy.after', [
                'user' => $user,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, '删除失败']);
        }
    }
}
