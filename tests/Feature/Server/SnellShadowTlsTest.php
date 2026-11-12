<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\ClashMeta;
use App\Protocols\SingBox;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use ReflectionProperty;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class SnellShadowTlsTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const PSK = '0123456789abcdef';

    private function snell(int $version, ?array $shadowTls, string $name = 'snell'): array
    {
        $settings = ['version' => $version, 'psk' => self::PSK, 'obfs_mode' => 'none', 'mode' => 'unshaped'];
        if ($shadowTls !== null) {
            $settings['shadow_tls'] = $shadowTls;
        }
        return [
            'type' => 'snell', 'name' => $name, 'host' => 'node.example.com', 'port' => 443,
            'server_port' => 443, 'rate' => 1, 'protocol_settings' => $settings,
        ];
    }

    private function behindShadowTls(int $version, string $name = 'snell'): array
    {
        return $this->snell($version, ['enabled' => true, 'server' => 'www.example.com', 'server_port' => 443], $name);
    }

    private function passes(array $data): bool
    {
        return Validator::make($data, ServerSave::create('/test', 'POST', $data)->rules())->passes();
    }

    /** Proxies the mihomo subscription lists for this User-Agent, keyed by name. */
    private function mihomoProxies(array $servers, string $userAgent): array
    {
        // handle() also reports the user's traffic in the response headers.
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        $protocol = new ClashMeta($user, $servers, 'meta', null, strtolower($userAgent));
        $config = Yaml::parse($protocol->handle()->getContent());
        return collect($config['proxies'] ?? [])->keyBy('name')->all();
    }

    public function test_admin_validation_requires_a_handshake_server_when_enabled(): void
    {
        $this->assertTrue($this->passes($this->behindShadowTls(5)));
        $this->assertTrue($this->passes($this->behindShadowTls(6)));
        $this->assertTrue($this->passes($this->snell(5, ['enabled' => false])));
        $this->assertTrue($this->passes($this->snell(5, null)));

        $this->assertFalse($this->passes($this->snell(5, ['enabled' => true])));
        $this->assertFalse($this->passes($this->snell(5, ['enabled' => true, 'server' => ''])));
        $this->assertFalse($this->passes(
            $this->snell(5, ['enabled' => true, 'server' => 'www.example.com', 'server_port' => 70000])
        ));
    }

    public function test_node_config_carries_the_handshake_server_only_when_enabled(): void
    {
        $enabled = ServerService::buildNodeConfig(new Server(
            $this->snell(5, ['enabled' => true, 'server' => 'www.example.com'])
        ));
        $this->assertSame('www.example.com', $enabled['shadow_tls_server']);
        $this->assertSame(443, $enabled['shadow_tls_port']);

        $custom = ServerService::buildNodeConfig(new Server(
            $this->snell(6, ['enabled' => true, 'server' => 'www.example.com', 'server_port' => 8443])
        ));
        $this->assertSame(8443, $custom['shadow_tls_port']);

        foreach ([null, ['enabled' => false, 'server' => 'www.example.com']] as $shadowTls) {
            $plain = ServerService::buildNodeConfig(new Server($this->snell(5, $shadowTls)));
            $this->assertArrayNotHasKey('shadow_tls_server', $plain);
            $this->assertArrayNotHasKey('shadow_tls_port', $plain);
        }
    }

    public function test_mihomo_lists_snell_only_behind_shadow_tls_and_only_for_a_core_that_reads_it(): void
    {
        $servers = [
            $this->behindShadowTls(5, 'v5'),
            $this->behindShadowTls(6, 'v6'),
            // Needs a Snell user key, which mihomo cannot send.
            $this->snell(5, null, 'userkey'),
        ];

        $current = $this->mihomoProxies($servers, 'clash.meta/v1.19.34');
        $this->assertSame(['v5', 'v6'], array_keys($current));
        $this->assertSame(
            ['name' => 'v5', 'type' => 'snell', 'server' => 'node.example.com', 'port' => 443,
                'psk' => self::PSK, 'udp' => true, 'reuse' => true,
                'obfs-opts' => ['mode' => 'shadow-tls', 'version' => 3, 'host' => 'www.example.com', 'password' => self::UUID],
                'version' => 4],
            $current['v5']
        );
        $this->assertSame(6, $current['v6']['version']);
        $this->assertSame('unshaped', $current['v6']['mode']);
        $this->assertSame(self::UUID, $current['v6']['obfs-opts']['password']);

        // Snell v6 arrived after Snell over ShadowTLS.
        $this->assertSame(['v5'], array_keys($this->mihomoProxies($servers, 'clash.meta/1.19.28')));
        $this->assertSame(['v5'], array_keys($this->mihomoProxies($servers, 'clash.meta/1.19.33')));
        $this->assertSame([], array_keys($this->mihomoProxies($servers, 'clash.meta/1.19.27')));
        // A GUI client reports its own version, not the version of its core.
        $this->assertSame([], array_keys($this->mihomoProxies($servers, 'clash-verge/v2.4.2')));
        $this->assertSame([], array_keys($this->mihomoProxies($servers, 'mihomo.party/v1.9.5 (clash.meta)')));
    }

    public function test_sing_box_keeps_user_key_nodes_and_drops_shadow_tls_nodes(): void
    {
        $servers = [$this->behindShadowTls(5, 'shadow-tls'), $this->snell(5, null, 'userkey')];
        $protocol = new SingBox(['uuid' => self::UUID], $servers, 'sing-box', '1.14.0');
        $kept = (new ReflectionProperty(SingBox::class, 'servers'))->getValue($protocol);
        $this->assertSame(['userkey'], array_column($kept, 'name'));
    }

    public function test_subscription_format_flag_preserves_the_real_core_user_agent(): void
    {
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        $servers = [$this->behindShadowTls(5, 'v5'), $this->behindShadowTls(6, 'v6')];
        foreach ([
            ['clash.meta/1.19.34', [], ['v5', 'v6']],
            ['clash.meta/1.19.34', ['flag' => 'meta'], ['v5', 'v6']],
            ['clash.meta/1.19.28', ['flag' => 'meta'], ['v5']],
            // A format override cannot claim support absent from the actual core.
            ['clash.meta/1.19.27', ['flag' => 'clash.meta/1.19.34'], []],
            ['', ['flag' => 'clash.meta/1.19.34'], []],
        ] as [$userAgent, $query, $expected]) {
            $request = Request::create('/api/v1/client/subscribe', 'GET', $query, [], [], ['HTTP_USER_AGENT' => $userAgent]);
            $response = app(ClientController::class)->doSubscribe($request, $user, $servers);
            $proxies = Yaml::parse($response->getContent())['proxies'] ?? [];
            $this->assertSame($expected, array_column($proxies, 'name'), json_encode([$userAgent, $query]));
        }
    }
}
