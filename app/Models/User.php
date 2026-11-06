<?php

namespace App\Models;

use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Symfony\Polyfill\Intl\Idn\Idn;
use Symfony\Polyfill\Intl\Normalizer\Normalizer;
use Symfony\Polyfill\Mbstring\Mbstring;

/**
 * App\Models\User
 *
 * @property int $id 用户ID
 * @property string $email 邮箱
 * @property string $password 密码
 * @property string|null $password_algo 加密方式
 * @property string|null $password_salt 加密盐
 * @property string $token 邀请码
 * @property string $uuid
 * @property int|null $invite_user_id 邀请人
 * @property int|null $plan_id 订阅ID
 * @property int|null $group_id 权限组ID
 * @property int|null $transfer_enable 流量(KB)
 * @property int|null $speed_limit 限速Mbps
 * @property int|null $u 上行流量
 * @property int|null $d 下行流量
 * @property int|null $banned 是否封禁
 * @property int|null $remind_expire 到期提醒
 * @property int|null $remind_traffic 流量提醒
 * @property int|null $expired_at 过期时间
 * @property int|null $balance 余额
 * @property int|null $commission_balance 佣金余额
 * @property float $commission_rate 返佣比例
 * @property int|null $commission_type 返佣类型
 * @property int|null $device_limit 设备限制数量
 * @property int|null $discount 折扣
 * @property int|null $last_login_at 最后登录时间
 * @property int|null $parent_id 父账户ID
 * @property int|null $is_admin 是否管理员
 * @property int|null $next_reset_at 下次流量重置时间
 * @property int|null $last_reset_at 上次流量重置时间
 * @property int|null $telegram_id Telegram ID
 * @property int $reset_count 流量重置次数
 * @property int $created_at
 * @property int $updated_at
 * @property bool $commission_auto_check 是否自动计算佣金
 *
 * @property-read User|null $invite_user 邀请人信息
 * @property-read \App\Models\Plan|null $plan 用户订阅计划
 * @property-read ServerGroup|null $group 权限组
 * @property-read \Illuminate\Database\Eloquent\Collection<int, InviteCode> $codes 邀请码列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Order> $orders 订单列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, StatUser> $stat 统计信息
 * @property-read \Illuminate\Database\Eloquent\Collection<int, Ticket> $tickets 工单列表
 * @property-read \Illuminate\Database\Eloquent\Collection<int, TrafficResetLog> $trafficResetLogs 流量重置记录
 * @property-read User|null $parent 父账户
 * @property-read string $subscribe_url 订阅链接（动态生成）
 */
class User extends Authenticatable
{
    use HasApiTokens;
    protected $table = 'v2_user';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'banned' => 'boolean',
        'is_admin' => 'boolean',
        'is_staff' => 'boolean',
        'remind_expire' => 'boolean',
        'remind_traffic' => 'boolean',
        'commission_auto_check' => 'boolean',
        'commission_rate' => 'float',
        'next_reset_at' => 'timestamp',
        'last_reset_at' => 'timestamp',
    ];
    protected $hidden = ['password', 'email_search'];

    public const COMMISSION_TYPE_SYSTEM = 0;
    public const COMMISSION_TYPE_PERIOD = 1;
    public const COMMISSION_TYPE_ONETIME = 2;
    protected function email(): Attribute
    {
        return Attribute::make(
            set: function (string $value): array {
                $email = self::normalizeEmail($value);
                return ['email' => $email, 'email_search' => self::searchableEmail($email)];
            },
        );
    }

    /**
     * Normalize an email consistently for storage, lookup and cache keys.
     */
    public static function normalizeEmail(string $email): string
    {
        $email = trim($email);
        $email = Normalizer::normalize($email, Normalizer::FORM_C) ?: $email;

        $at = strrpos($email, '@');
        if ($at === false) {
            return Mbstring::mb_strtolower($email, 'UTF-8');
        }

        // Use the locked Unicode tables, even when native extensions exist.
        // PHP 8.3 changed native lowercasing of Greek final sigma.
        $local = Mbstring::mb_strtolower(substr($email, 0, $at), 'UTF-8');
        $local = Normalizer::normalize($local, Normalizer::FORM_C) ?: $local;

        return $local . '@' . self::normalizeEmailDomain(substr($email, $at + 1));
    }

    public static function normalizeEmailDomain(string $domain): string
    {
        $domain = trim($domain);
        $asciiDomain = Idn::idn_to_ascii(
            $domain,
            IDNA_USE_STD3_RULES | IDNA_CHECK_BIDI | IDNA_CHECK_CONTEXTJ | IDNA_NONTRANSITIONAL_TO_ASCII,
            INTL_IDNA_VARIANT_UTS46
        );

        return $asciiDomain === false ? Mbstring::mb_strtolower($domain, 'UTF-8') : strtolower($asciiDomain);
    }

    public static function searchableEmail(string $email): string
    {
        $email = self::normalizeEmail($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }

        $domain = substr($email, $at + 1);
        $unicode = Idn::idn_to_utf8($domain, IDNA_NONTRANSITIONAL_TO_UNICODE, INTL_IDNA_VARIANT_UTS46);
        return substr($email, 0, $at + 1) . ($unicode === false ? $domain : $unicode);
    }

    public static function applyEmailSearch(Builder|QueryBuilder $query, string $value, bool $negated = false): void
    {
        $escape = static fn (string $text) => '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $text) . '%';
        $patterns = [
            'email' => $escape(self::normalizeEmail($value)),
            'email_search' => $escape(self::searchableEmail($value)),
        ];
        $query->where(function ($query) use ($patterns, $negated) {
            $operator = $negated ? 'NOT LIKE' : 'LIKE';
            foreach ($patterns as $column => $pattern) {
                $sql = $query->getGrammar()->wrap($column) . " {$operator} ? ESCAPE '!'";
                $method = !$negated && $column === 'email_search' ? 'orWhereRaw' : 'whereRaw';
                $query->{$method}($sql, [$pattern]);
            }
        });
    }

    /**
     * Query by the canonical email identity.
     */
    public function scopeByEmail(Builder $query, string $email): Builder
    {
        return $query->where('email', self::normalizeEmail($email));
    }

    // 获取邀请人信息
    public function invite_user(): BelongsTo
    {
        return $this->belongsTo(self::class, 'invite_user_id', 'id');
    }

    /**
     * 获取用户订阅计划
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id', 'id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(ServerGroup::class, 'group_id', 'id');
    }

    // 获取用户邀请码列表
    public function codes(): HasMany
    {
        return $this->hasMany(InviteCode::class, 'user_id', 'id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'user_id', 'id');
    }

    public function stat(): HasMany
    {
        return $this->hasMany(StatUser::class, 'user_id', 'id');
    }

    // 关联工单列表
    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'user_id', 'id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id', 'id');
    }

    /**
     * 关联流量重置记录
     */
    public function trafficResetLogs(): HasMany
    {
        return $this->hasMany(TrafficResetLog::class, 'user_id', 'id');
    }

    /**
     * 检查用户是否处于活跃状态
     */
    public function isActive(): bool
    {
        return !$this->banned && 
               ($this->expired_at === null || $this->expired_at > time()) &&
               $this->plan_id !== null;
    }

    /** 
     * 检查用户是否可用节点流量且充足
     */
    public function isAvailable(): bool
    {     
        return $this->isActive() && $this->getRemainingTraffic() > 0;   
    }

    /**
     * 检查是否需要重置流量
     */
    public function shouldResetTraffic(): bool
    {
        return $this->isActive() &&
               $this->next_reset_at !== null &&
               $this->next_reset_at <= time();
    }

    /**
     * 获取总使用流量
     */
    public function getTotalUsedTraffic(): int
    {
        return ($this->u ?? 0) + ($this->d ?? 0);
    }

    /**
     * 获取剩余流量
     */
    public function getRemainingTraffic(): int
    {
        $used = $this->getTotalUsedTraffic();
        $total = $this->transfer_enable ?? 0;
        return max(0, $total - $used);
    }

    /**
     * 获取流量使用百分比
     */
    public function getTrafficUsagePercentage(): float
    {
        $total = $this->transfer_enable ?? 0;
        if ($total <= 0) {
            return 0;
        }
        
        $used = $this->getTotalUsedTraffic();
        return min(100, ($used / $total) * 100);
    }
}
