<?php

namespace Tests\Feature\Admin;

use App\Models\Plan;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserGenerateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();

        Sanctum::actingAs(User::withoutEvents(fn () => User::create([
            'email' => 'admin@example.com',
            'password' => '',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => true,
        ])));
    }

    public static function generationCases(): iterable
    {
        $modes = [
            'single' => ['email_prefix' => 'new-user'],
            'random batch' => ['generate_count' => 2],
            'prefixed batch' => ['email_prefix' => 'new-user', 'generate_count' => 2],
        ];

        foreach ($modes as $mode => $input) {
            yield $mode . ' limited plan' => [$input, true, 3];
            yield $mode . ' unlimited plan' => [$input, true, null];
            yield $mode . ' zero limit plan' => [$input, true, 0];
            yield $mode . ' without plan' => [$input, false, null];
        }
    }

    #[DataProvider('generationCases')]
    public function test_generated_users_inherit_the_selected_plan_device_limit(array $input, bool $withPlan, ?int $deviceLimit): void
    {
        $plan = $withPlan ? Plan::create([
            'name' => 'Test plan',
            'transfer_enable' => 100,
            'device_limit' => $deviceLimit,
        ]) : null;

        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $this->postJson('/api/v2/' . $path . '/user/generate', array_merge($input, [
            'email_suffix' => 'example.com',
            'plan_id' => $plan?->id,
        ]))->assertOk();

        $users = User::where('is_admin', false)->get();
        $this->assertCount($input['generate_count'] ?? 1, $users);
        foreach ($users as $user) {
            $this->assertSame($plan?->id, $user->plan_id);
            $this->assertSame($deviceLimit, $user->device_limit);
        }
    }
}
