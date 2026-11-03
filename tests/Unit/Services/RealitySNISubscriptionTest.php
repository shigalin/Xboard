<?php

namespace Tests\Unit\Services;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\User;
use App\Protocols\General;
use App\Services\ServerService;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory;
use PHPUnit\Framework\TestCase;

class RealitySNISubscriptionTest extends TestCase
{
    private Manager $database;

    protected function setUp(): void
    {
        parent::setUp();
        // No application .env, external database or Redis is used by this test.
        $container = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Cache::swap(new CacheRepository(new ArrayStore()));
        $this->database = new Manager($container);
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:']);
        $this->database->setAsGlobal();
        $this->database->bootEloquent();
        $this->database->schema()->create('v2_server', function (Blueprint $table) {
            $table->increments('id');
            foreach (['name', 'type', 'host', 'port', 'group_ids', 'protocol_settings'] as $column) {
                $table->text($column);
            }
            $table->integer('server_port');
            $table->float('rate')->default(1);
            $table->boolean('show')->default(true);
            $table->integer('sort')->default(0);
            $table->integer('transfer_enable')->nullable();
            $table->integer('u')->default(0);
            $table->integer('d')->default(0);
            $table->integer('created_at')->nullable();
            $table->integer('updated_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        $this->database->getConnection()->disconnect();
        Server::unsetConnectionResolver();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
        parent::tearDown();
    }

    private function makeServer(string $names): Server
    {
        return Server::create([
            'name' => 'REALITY test', 'type' => 'vless', 'host' => 'node.example.com',
            'port' => '443', 'server_port' => 443, 'group_ids' => ['1'], 'rate' => 1,
            'protocol_settings' => [
                'tls' => 2, 'network' => 'tcp', 'flow' => 'xtls-rprx-vision',
                'reality_settings' => ['server_name' => $names, 'server_port' => 8443,
                    'public_key' => 'test-public-key', 'short_id' => 'abcd'],
            ],
        ]);
    }

    public function test_node_api_keeps_list_while_subscription_uses_first_name(): void
    {
        $names = ' First.example.com. , second.example.com';
        $node = $this->makeServer($names);
        $user = new User(['group_id' => 1, 'uuid' => '11111111-1111-4111-8111-111111111111']);
        $clientServers = ServerService::getAvailableServers($user);

        $this->assertCount(1, $clientServers);
        $this->assertSame('first.example.com', data_get($clientServers[0], 'protocol_settings.reality_settings.server_name'));
        $uri = General::buildVless($user->uuid, $clientServers[0]);
        parse_str(parse_url(trim($uri), PHP_URL_QUERY), $query);
        $this->assertSame('first.example.com', $query['sni']);

        $nodeConfig = ServerService::buildNodeConfig($node->fresh());
        $this->assertSame($names, $nodeConfig['tls_settings']['server_name']);
        $this->assertSame(8443, (int) $nodeConfig['tls_settings']['server_port']);
        $this->assertSame($names, data_get($node->fresh(), 'protocol_settings.reality_settings.server_name'));
    }

    public function test_single_name_subscription_is_unchanged(): void
    {
        $this->makeServer('single.example.com');
        $servers = ServerService::getAvailableServers(new User(['group_id' => 1, 'uuid' => 'test-user']));
        $this->assertSame('single.example.com', data_get($servers[0], 'protocol_settings.reality_settings.server_name'));
    }

    public function test_existing_panel_field_accepts_comma_list_and_one_target_port(): void
    {
        $data = $this->makeServer('first.example.com,second.example.com')->toArray();
        $request = ServerSave::create('/test', 'POST', $data);
        $validator = new Factory(new Translator(new ArrayLoader(), 'en'));
        $result = $validator->make($data, $request->rules());
        $this->assertTrue($result->passes(), implode('; ', $result->errors()->all()));
    }
}
