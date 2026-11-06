<?php

namespace Tests\Feature\Admin;

use App\Jobs\TrafficFetchJob;
use App\Models\Plan;
use App\Models\Server;
use App\Models\User;
use App\Models\UserServerTraffic;
use App\Models\TrafficResetLog;
use App\Utils\Helper;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailIdentityCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Redis::shouldReceive('sadd')->andReturn(1)->byDefault();
        Sanctum::actingAs($this->user(['is_admin' => true]));
    }

    private function user(array $attributes = []): User
    {
        return User::withoutEvents(fn () => User::create(array_merge([
            'email' => Helper::guid() . '@example.com', 'password' => '',
            'uuid' => Helper::guid(true), 'token' => Helper::guid(),
        ], $attributes)));
    }

    private function endpoint(string $action): string
    {
        $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $path . '/user/' . $action;
    }

    public static function banScopes(): iterable
    {
        yield 'selected' => ['selected'];
        yield 'filtered by equivalent email' => ['filtered'];
        yield 'all' => ['all'];
    }

    #[DataProvider('banScopes')]
    public function test_bulk_ban_revokes_only_the_tokens_in_scope(string $scope): void
    {
        $target = $this->user(['email' => 'José@example.com']);
        $selected = $this->user();
        $other = $this->user();
        $target->createToken('first session');
        $target->createToken('second session');
        $selected->createToken('selected session');
        $other->createToken('unaffected session');

        $this->postJson($this->endpoint('ban'), [
            'scope' => $scope,
            'user_ids' => [$selected->id],
            'filter' => [['id' => 'email', 'value' => "eq:JOSE\u{0301}@EXAMPLE.COM"]],
        ])->assertOk();

        $this->assertSame($scope !== 'selected', $target->fresh()->banned);
        $this->assertSame($scope === 'selected' ? 2 : 0, $target->tokens()->count());
        $this->assertSame($scope !== 'filtered', $selected->fresh()->banned);
        $this->assertSame($scope === 'filtered' ? 1 : 0, $selected->tokens()->count());
        $this->assertSame($scope === 'all', $other->fresh()->banned);
        $this->assertSame($scope === 'all' ? 0 : 1, $other->tokens()->count());
    }

    public function test_equivalent_email_update_preserves_node_traffic(): void
    {
        $user = $this->user(['email' => 'José@example.com']);
        $server = Server::create([
            'name' => 'Test node', 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
        ]);
        (new TrafficFetchJob($server->toArray(), [$user->id => [100, 200]], $server->type, time()))->handle();

        $this->postJson($this->endpoint('update'), [
            'id' => $user->id, 'email' => "JOSE\u{0301}@EXAMPLE.COM",
        ])->assertOk();

        $this->assertSame('josé@example.com', $user->fresh()->email);
        $this->assertSame(1, UserServerTraffic::where('user_id', $user->id)->count());
        $this->getJson($this->endpoint('getNodeTraffic') . '?id=' . $user->id)->assertOk()
            ->assertJsonPath('data.summary.total_used', 300)
            ->assertJsonPath('data.summary.attributed_total', 300);
    }

    public function test_bulk_ban_and_token_revocation_use_the_same_locked_id_snapshot(): void
    {
        $target = $this->user(['u' => 100]);
        $lateMatch = $this->user(['u' => 0]);
        $target->createToken('target session');
        $lateMatch->createToken('late session');
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$armed, $lateMatch) {
            if ($armed && preg_match('/^select ["`\[]id["`\]] from ["`\[]v2_user["`\]]/i', $query->sql)) {
                $armed = false;
                // Simulate a row becoming eligible after the selection snapshot,
                // as a concurrent writer can under READ COMMITTED isolation.
                DB::table('v2_user')->where('id', $lateMatch->id)->update(['u' => 200]);
            }
        });

        $this->postJson($this->endpoint('ban'), [
            'scope' => 'filtered', 'filter' => [['id' => 'u', 'value' => 'gte:100']],
        ])->assertOk();
        $this->assertFalse($armed);
        $this->assertTrue($target->fresh()->banned);
        $this->assertSame(0, $target->tokens()->count());
        $this->assertFalse($lateMatch->fresh()->banned);
        $this->assertSame(1, $lateMatch->tokens()->count());
    }

    public function test_invite_relationship_can_be_set_and_cleared_but_cannot_form_a_cycle(): void
    {
        $parent = $this->user();
        $child = $this->user();
        $url = $this->endpoint('setInviteUser');

        $this->postJson($url, ['id' => $child->id, 'invite_user_id' => $parent->id])->assertOk();
        $this->assertSame($parent->id, $child->fresh()->invite_user_id);
        $this->postJson($url, ['id' => $parent->id, 'invite_user_id' => $child->id])->assertUnprocessable();
        $this->assertNull($parent->fresh()->invite_user_id);
        $this->postJson($url, ['id' => $child->id, 'invite_user_id' => $child->id])->assertUnprocessable();
        $this->postJson($url, ['id' => $child->id, 'invite_user_id' => null])->assertOk();
        $this->assertNull($child->fresh()->invite_user_id);
    }

    public function test_international_email_generation_preserves_plan_device_limit_and_default_password(): void
    {
        $plan = Plan::create(['name' => 'Test plan', 'transfer_enable' => 100, 'device_limit' => 3]);

        $this->postJson($this->endpoint('generate'), [
            'email_prefix' => 'José', 'email_suffix' => '例子.中国', 'plan_id' => $plan->id,
        ])->assertOk();

        $user = User::byEmail('José@例子.中国')->firstOrFail();
        $this->assertSame('josé@xn--fsqu00a.xn--fiqs8s', $user->email);
        $this->assertSame(3, $user->device_limit);
        $this->assertTrue(Hash::check($user->email, $user->password));
    }

    public static function emailSearchCases(): iterable
    {
        yield 'whole international domain' => ['例子.中国'];
        yield 'one domain label' => ['例子'];
        yield 'part of a domain label' => ['子'];
        yield 'full international email' => ['USER@例子.中国'];
        yield 'punycode fragment' => ['xn--fsqu00a'];
    }

    #[DataProvider('emailSearchCases')]
    public function test_email_search_matches_both_stored_and_unicode_representations(string $keyword): void
    {
        $target = $this->user(['email' => 'user@例子.中国']);
        $child = $this->user(['invite_user_id' => $target->id]);

        $this->postJson($this->endpoint('fetch'), [
            'filter' => [['id' => 'email', 'value' => $keyword]],
        ])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $target->id);
        $this->postJson($this->endpoint('fetch'), [
            'filter' => [['id' => 'invite_user.email', 'value' => 'like:' . $keyword]],
        ])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $child->id);
        $response = $this->postJson($this->endpoint('fetch'), [
            'filter' => [['id' => 'email', 'value' => 'notlike:' . $keyword]],
        ])->assertOk()->assertJsonPath('total', 2);
        $this->assertNotContains($target->id, array_column($response->json('data'), 'id'));
        $this->assertArrayNotHasKey('email_search', $target->toArray());
    }

    public function test_email_search_treats_wildcard_characters_literally_and_tracks_email_edits(): void
    {
        $target = $this->user(['email' => 'a_%!@例子.中国']);
        $this->user(['email' => 'axxx@example.com']);
        $this->postJson($this->endpoint('fetch'), [
            'filter' => [['id' => 'email', 'value' => '_%!']],
        ])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $target->id);

        $this->postJson($this->endpoint('update'), ['id' => $target->id, 'email' => 'new@测试.中国'])->assertOk();
        $this->postJson($this->endpoint('fetch'), [
            'filter' => [['id' => 'email', 'value' => '测试']],
        ])->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $target->id);
        $this->postJson($this->endpoint('fetch'), [
            'filter' => [['id' => 'email', 'value' => '例子']],
        ])->assertOk()->assertJsonPath('total', 0);
    }

    public function test_traffic_reset_logs_accept_unicode_domain_fragments(): void
    {
        $target = $this->user(['email' => 'user@例子.中国']);
        TrafficResetLog::create([
            'user_id' => $target->id, 'reset_type' => 'manual', 'reset_time' => now(),
            'old_upload' => 1, 'old_download' => 2, 'old_total' => 3,
            'new_upload' => 0, 'new_download' => 0, 'new_total' => 0, 'trigger_source' => 'manual',
        ]);
        $url = str_replace('/user/update', '/traffic-reset/logs', $this->endpoint('update'));
        $this->getJson($url . '?' . http_build_query(['user_email' => '子']))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.user_id', $target->id);
    }

    public function test_actual_user_edit_validates_invites_and_preserves_omitted_relationships(): void
    {
        $parent = $this->user();
        $child = $this->user(['invite_user_id' => $parent->id]);
        $url = $this->endpoint('update');

        $this->postJson($url, ['id' => $child->id, 'remarks' => 'unchanged invite'])->assertOk();
        $this->assertSame($parent->id, $child->fresh()->invite_user_id);
        foreach ([$child->email, 'missing@example.com'] as $email) {
            $this->postJson($url, ['id' => $child->id, 'invite_user_email' => $email])->assertUnprocessable();
            $this->assertSame($parent->id, $child->fresh()->invite_user_id);
        }
        $this->postJson($url, ['id' => $parent->id, 'invite_user_email' => $child->email])->assertUnprocessable();
        $this->assertNull($parent->fresh()->invite_user_id);
        $this->postJson($url, ['id' => $child->id, 'invite_user_email' => null])->assertOk();
        $this->assertNull($child->fresh()->invite_user_id);
    }

    public function test_email_search_endpoints_reject_array_parameters(): void
    {
        $ticketUrl = str_replace('/user/fetch', '/ticket/fetch', $this->endpoint('fetch'));
        $this->getJson($ticketUrl . '?' . http_build_query(['email' => ['user@example.com']]))
            ->assertUnprocessable();

        $logsUrl = str_replace('/user/fetch', '/traffic-reset/logs', $this->endpoint('fetch'));
        $this->getJson($logsUrl . '?' . http_build_query(['user_email' => ['user@example.com']]))
            ->assertUnprocessable();
    }
}
