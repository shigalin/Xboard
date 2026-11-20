<?php

namespace Tests\Feature\Admin;

use App\Models\Server;
use App\Models\ServerGroup;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TrafficRankTest extends TestCase
{
    use RefreshDatabase;

    private function endpoint(string $type): string
    {
        $securePath = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        return '/api/v2/' . $securePath . '/stat/getTrafficRank?type=' . $type
            . '&start_time=' . (time() - 86400) . '&end_time=' . (time() + 60);
    }

    private function createUser(bool $admin): User
    {
        return User::withoutEvents(fn () => User::create([
            'email' => Helper::guid() . '@example.com',
            'password' => '',
            'uuid' => Helper::guid(true),
            'token' => Helper::guid(),
            'is_admin' => $admin,
        ]));
    }

    private function createGroup(string $name): ServerGroup
    {
        return ServerGroup::forceCreate(['name' => $name]);
    }

    private function createServer(string $name, array $groupIds): Server
    {
        return Server::withoutEvents(fn () => Server::create([
            'name' => $name, 'type' => 'vless', 'host' => '127.0.0.1',
            'port' => 443, 'server_port' => 443, 'rate' => 1,
            'group_ids' => $groupIds,
        ]));
    }

    public function test_node_rank_excludes_nodes_only_in_admin_groups(): void
    {
        Sanctum::actingAs($this->createUser(true));
        $adminGroup = $this->createGroup('Admin');
        $userGroup = $this->createGroup('VIP');
        $adminOnly = $this->createServer('admin only', [(string) $adminGroup->id]);
        $mixed = $this->createServer('mixed', [(string) $adminGroup->id, (string) $userGroup->id]);
        $userNode = $this->createServer('user', [(string) $userGroup->id]);
        $ungrouped = $this->createServer('ungrouped', []);

        foreach ([[$adminOnly, 9000], [$mixed, 300], [$userNode, 200], [$ungrouped, 100]] as [$server, $traffic]) {
            StatServer::create([
                'server_id' => $server->id, 'server_type' => $server->type,
                'u' => $traffic, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
            ]);
        }

        $response = $this->getJson($this->endpoint('node'))->assertOk();
        $this->assertSame(
            [(string) $mixed->id, (string) $userNode->id, (string) $ungrouped->id],
            array_column($response->json('data'), 'id')
        );
    }

    public function test_node_rank_classifies_legacy_group_ids_like_full_sync(): void
    {
        Sanctum::actingAs($this->createUser(true));
        // ID 1, so a boolean true is group 1, as full sync's whereIn binds it.
        $adminGroup = ServerGroup::forceCreate(['id' => 1, 'name' => 'admin']);
        // An explicit ID too: PostgreSQL's sequence would hand out 1 again.
        $otherGroup = ServerGroup::forceCreate(['id' => 2, 'name' => 'Ádmin']);
        $adminOnly = $this->createServer('admin only', [$adminGroup->id]);
        $nested = $this->createServer('nested', [[(string) $adminGroup->id]]);
        // Full sync flattens the nested ID, so this node also serves another group.
        $mixed = $this->createServer('mixed', [(string) $adminGroup->id, true, [(string) $otherGroup->id]]);
        $lookalike = $this->createServer('lookalike', [(string) $otherGroup->id]);
        $legacy = [];
        foreach ([
            'scalar' => json_encode((string) $adminGroup->id),
            'object' => json_encode(['first' => (string) $adminGroup->id]),
            'boolean' => json_encode([true]),
            // Serves no group, so it is not admin-only.
            'intScalar' => json_encode($adminGroup->id),
        ] as $name => $groupIds) {
            $legacy[$name] = $this->createServer($name, []);
            Server::whereKey($legacy[$name]->id)->toBase()->update(['group_ids' => $groupIds]);
        }

        foreach ([
            [$adminOnly, 9000], [$nested, 8000], [$legacy['scalar'], 7000], [$legacy['object'], 6000],
            [$legacy['boolean'], 5000], [$mixed, 400], [$lookalike, 300], [$legacy['intScalar'], 200],
        ] as [$server, $traffic]) {
            StatServer::create([
                'server_id' => $server->id, 'server_type' => $server->type,
                'u' => $traffic, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
            ]);
        }

        $response = $this->getJson($this->endpoint('node'))->assertOk();
        $this->assertSame(
            array_map(fn ($server) => (string) $server->id, [$mixed, $lookalike, $legacy['intScalar']]),
            array_column($response->json('data'), 'id')
        );
    }

    public function test_user_rank_excludes_administrators(): void
    {
        $admin = $this->createUser(true);
        $user = $this->createUser(false);
        Sanctum::actingAs($admin);

        foreach ([[$admin, 9000], [$user, 100]] as [$owner, $traffic]) {
            StatUser::create([
                'user_id' => $owner->id, 'server_rate' => '1.00',
                'u' => $traffic, 'd' => 0, 'record_type' => 'd', 'record_at' => time() - 3600,
            ]);
        }

        $response = $this->getJson($this->endpoint('user'))->assertOk();
        $this->assertSame([(string) $user->id], array_column($response->json('data'), 'id'));
    }
}
