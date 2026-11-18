<?php

namespace Tests\Feature\Server;

use App\Http\Controllers\V1\Client\ClientController;
use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Models\SubscribeTemplate;
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
        $protocol = new SingBox(['uuid' => self::UUID], $servers, 'sing-box', '1.14.4');
        $kept = (new ReflectionProperty(SingBox::class, 'servers'))->getValue($protocol);
        $this->assertSame(['userkey'], array_column($kept, 'name'));
    }

    public function test_subscription_format_flag_preserves_the_real_core_user_agent(): void
    {
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        $servers = [$this->behindShadowTls(5, 'v5'), $this->behindShadowTls(6, 'v6')];
        foreach ([
            ['clash.meta/1.19.34', [], ['v5', 'v6']],
            ['mihomo/alpha-2be2f08', [], ['v5', 'v6']],
            ['mihomo/v1.19.28', [], ['v5']],
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
    public function test_handshake_server_accepts_only_dns_hostnames(): void
    {
        foreach (['example.com', 'TLS.Example.COM', 'node-1.example.com', 'xn--bcher-kva.example', str_repeat('a', 63) . '.example'] as $host) {
            $this->assertTrue($this->passes($this->snell(5, ['enabled' => true, 'server' => $host])), $host);
        }
        foreach (['https://example.com', 'example.com:443', 'example.com/path', 'user@example.com', ' example.com', 'example.com ', '*.example.com', 'foo_bar.example', '-bad.example', 'bad-.example', 'a..example', str_repeat('a', 64) . '.example', '127.0.0.1', '2001:db8::1', '[::1]', '例子.测试'] as $host) {
            $this->assertFalse($this->passes($this->snell(5, ['enabled' => true, 'server' => $host])), $host);
        }
    }

    public function test_sing_box_subscription_uses_core_ua_before_format_flag_version(): void
    {
        SubscribeTemplate::setContent('singbox', json_encode([
            'outbounds' => [['type' => 'direct', 'tag' => 'direct'], ['type' => 'block', 'tag' => 'block']],
            'route' => ['rules' => [['domain' => ['blocked.example'], 'outbound' => 'block']]],
        ]));
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        try {
            foreach ([
                ['sing-box 1.14.0', 'sing-box/1.12.0', true],
                ['sing-box 1.12.0', 'sing-box/1.14.0', false],
                ['SFM/1.0 (sing-box v1.14.0)', 'sing-box/1.12.0', true],
                // Without a core version in the UA, retain the existing flag fallback.
                ['', 'sing-box/1.12.0', false],
                ['', 'sing-box/1.14.0', true],
            ] as [$ua, $flag, $modern]) {
                $request = Request::create('/api/v1/client/subscribe', 'GET', ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
                $response = app(ClientController::class)->doSubscribe($request, $user, []);
                $config = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $rule = $config['route']['rules'][0];
                $this->assertSame($modern, !in_array('block', array_column($config['outbounds'], 'type')), $ua . ' / ' . $flag);
                $this->assertSame($modern ? 'reject' : 'block', $modern ? $rule['action'] : $rule['outbound']);
            }
        } finally {
            SubscribeTemplate::flushCache('singbox');
        }
    }

    public function test_independent_psk_contract_and_plain_mihomo_subscription(): void
    {
        $expected = self::UUID;
        foreach ([5, 6] as $version) {
            foreach ([false, true] as $shadow) {
                $node = $shadow ? $this->behindShadowTls($version) : $this->snell($version, null);
                $node['protocol_settings']['psk_mode'] = 'user';
                unset($node['protocol_settings']['psk']);
                if ($shadow) {
                    $this->assertFalse($this->passes($node), 'Shared ShadowTLS password is required');
                    $node['protocol_settings']['shadow_tls']['password'] = 'short';
                    $this->assertFalse($this->passes($node));
                    $node['protocol_settings']['shadow_tls']['password'] = 'shared-shadowtls-node-password';
                }
                $this->assertTrue($this->passes($node));
                $this->assertSame('user', ServerService::buildNodeConfig(new Server($node))['psk_mode']);
                $proxy = $this->mihomoProxies([$node], 'clash.meta/1.19.34')['snell'];
                $this->assertSame($expected, $proxy['psk']);
                $other = ClashMeta::buildSnell('22222222-2222-4222-8222-222222222222', $node);
                $this->assertNotSame($proxy['psk'], $other['psk']);
                $this->assertArrayNotHasKey('userkey', $proxy);
                $this->assertSame($shadow, isset($proxy['obfs-opts']));
                if ($shadow) {
                    $this->assertSame('shared-shadowtls-node-password', $proxy['obfs-opts']['password']);
                    $this->assertSame('shared-shadowtls-node-password', ServerService::buildNodeConfig(new Server($node))['shadow_tls_password']);
                } else {
                    $sb = new SingBox(['uuid' => self::UUID], [$node], 'sing-box', '1.14.4');
                    $outbound = (new \ReflectionMethod(SingBox::class, 'buildSnell'))->invoke($sb, self::UUID, $node);
                    $this->assertSame($expected, $outbound['psk']);
                    $this->assertArrayNotHasKey('userkey', $outbound);
                }
                $node['protocol_settings']['mode'] = 'unsafe-raw';
                $this->assertFalse($this->passes($node));
            }
        }
        $legacy = new Server($this->snell(5, null));
        $this->assertSame('shared', $legacy->protocol_settings['psk_mode']);
    }

    public function test_shared_psk_requires_a_userkey_with_optional_shadow_tls_password(): void
    {
        $node = $this->behindShadowTls(6);
        $node['protocol_settings']['psk_mode'] = 'shared_userkey';
        $this->assertTrue($this->passes($node));
        $this->assertSame(self::UUID, $this->mihomoProxies([$node], 'clash.meta/alpha-3c0d10a')['snell']['obfs-opts']['password']);
        $node['protocol_settings']['shadow_tls']['password'] = 'short';
        $this->assertFalse($this->passes($node));
        $node['protocol_settings']['shadow_tls']['password'] = 'shared-shadowtls-node-password';
        $this->assertTrue($this->passes($node));
        $disabled = $node;
        $disabled['protocol_settings']['shadow_tls']['enabled'] = false;
        $this->assertTrue($this->passes($disabled));
        $plain = $this->mihomoProxies([$disabled], 'clash.meta/alpha-2be2f08')['snell'];
        $this->assertSame(self::UUID, $plain['userkey']);
        $this->assertArrayNotHasKey('obfs-opts', $plain);
        $this->assertArrayNotHasKey('shadow_tls_password', ServerService::buildNodeConfig(new Server($disabled)));
        $missingPsk = $node;
        unset($missingPsk['protocol_settings']['psk']);
        $this->assertFalse($this->passes($missingPsk));

        $config = ServerService::buildNodeConfig(new Server($node));
        $this->assertSame('shared_userkey', $config['psk_mode']);
        $this->assertSame(self::PSK, $config['server_key']);
        $this->assertSame('shared-shadowtls-node-password', $config['shadow_tls_password']);

        $this->assertSame([], $this->mihomoProxies([$node], 'clash.meta/1.19.34'));
        $proxy = $this->mihomoProxies([$node], 'clash.meta/alpha-2be2f08')['snell'];
        $this->assertSame(self::PSK, $proxy['psk']);
        $this->assertSame(self::UUID, $proxy['userkey']);
        $this->assertSame('shared-shadowtls-node-password', $proxy['obfs-opts']['password']);
        $node['protocol_settings']['version'] = 5;
        $this->assertSame([], $this->mihomoProxies([$node], 'clash.meta/alpha-3c0d10a'));

        $node['protocol_settings']['psk_mode'] = 'user';
        $proxy = $this->mihomoProxies([$node], 'clash.meta/alpha-3c0d10a')['snell'];
        $this->assertSame(self::UUID, $proxy['psk']);
        $this->assertArrayNotHasKey('userkey', $proxy);
        $this->assertSame('shared-shadowtls-node-password', $proxy['obfs-opts']['password']);
    }

    public function test_sing_box_chains_shared_shadow_tls_in_both_psk_modes(): void
    {
        $template = ['route' => ['rules' => []], 'outbounds' => [
            ['type' => 'selector', 'tag' => 'proxy', 'outbounds' => ['direct']],
            ['type' => 'direct', 'tag' => 'direct'],
            ['type' => 'direct', 'tag' => 'user · ShadowTLS'],
        ]];
        SubscribeTemplate::setContent('singbox', json_encode($template));
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        $nodes = [];
        foreach (['shared_userkey' => 5, 'user' => 6] as $mode => $version) {
            $node = $this->behindShadowTls($version, $mode);
            $node['protocol_settings']['psk_mode'] = $mode;
            $node['protocol_settings']['obfs_mode'] = 'http';
            $node['protocol_settings']['shadow_tls']['password'] = 'shared-shadowtls-node-password';
            $nodes[] = $node;
        }
        $perUserShadow = $nodes[0];
        $perUserShadow['name'] = 'per-user-shadowtls';
        unset($perUserShadow['protocol_settings']['shadow_tls']['password']);
        $nodes[] = $perUserShadow;
        try {
            $modern = new SingBox($user, $nodes, 'sing-box', '1.14.4');
            $outbounds = array_column(json_decode($modern->handle()->getContent(), true, 512, JSON_THROW_ON_ERROR)['outbounds'], null, 'tag');
            $this->assertSame(['direct', 'shared_userkey', 'user', 'per-user-shadowtls'], $outbounds['proxy']['outbounds']);
            $this->assertSame('direct', $outbounds['user · ShadowTLS']['type']);
            $this->assertSame(self::PSK, $outbounds['shared_userkey']['psk']);
            $this->assertSame(self::UUID, $outbounds['shared_userkey']['userkey']);
            $this->assertSame(4, $outbounds['shared_userkey']['version']);
            $this->assertArrayNotHasKey('obfs_mode', $outbounds['shared_userkey']);
            $this->assertSame(self::UUID, $outbounds['user']['psk']);
            $this->assertArrayNotHasKey('userkey', $outbounds['user']);
            $this->assertSame(self::UUID, $outbounds['per-user-shadowtls']['userkey']);
            $this->assertSame(self::UUID, $outbounds[$outbounds['per-user-shadowtls']['detour']]['password']);
            foreach (['shared_userkey', 'user'] as $mode) {
                $gateway = $outbounds[$outbounds[$mode]['detour']];
                $this->assertSame('shadowtls', $gateway['type']);
                $this->assertSame(3, $gateway['version']);
                $this->assertSame('node.example.com', $gateway['server']);
                $this->assertSame('shared-shadowtls-node-password', $gateway['password']);
                $this->assertSame(['enabled' => true, 'server_name' => 'www.example.com'], $gateway['tls']);
            }
            $old = new SingBox($user, $nodes, 'sing-box', '1.13.0');
            $this->assertSame($template['outbounds'], json_decode($old->handle()->getContent(), true, 512, JSON_THROW_ON_ERROR)['outbounds']);
        } finally {
            SubscribeTemplate::flushCache('singbox');
        }
    }

    public function test_seeded_sing_box_template_matches_the_reported_core(): void
    {
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        foreach (['1.10.0' => false, '1.12.0' => true, '1.13.0' => true, '1.14.0-alpha.38' => true] as $version => $modern) {
            $request = Request::create('/api/v1/client/subscribe', 'GET', [], [], [], ['HTTP_USER_AGENT' => "sing-box/$version"]);
            $response = app(ClientController::class)->doSubscribe($request, $user, [$this->snell(6, null)]);
            $config = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);

            if ($modern) {
                $this->assertSame('local', $config['route']['default_domain_resolver']);
                $this->assertSame(['https', 'https'], array_column($config['dns']['servers'], 'type'));
                $this->assertContains('sniff', array_column($config['route']['rules'], 'action'));
                $this->assertContains('hijack-dns', array_column($config['route']['rules'], 'action'));
                $this->assertSame([], array_column($config['inbounds'], 'sniff'));
            } else {
                $this->assertArrayHasKey('address', $config['dns']['servers'][0]);
                $this->assertTrue($config['inbounds'][0]['sniff']);
            }
        }
    }

    public function test_sing_box_snell_gate_prefers_the_actual_core_user_agent(): void
    {
        $user = ['uuid' => self::UUID, 'u' => 0, 'd' => 0, 'transfer_enable' => 0, 'expired_at' => null];
        $node = $this->snell(6, null);
        $node['protocol_settings']['psk_mode'] = 'user';
        $wrapped = $this->behindShadowTls(6, 'wrapped');
        $wrapped['protocol_settings']['psk_mode'] = 'user';
        $wrapped['protocol_settings']['shadow_tls']['password'] = 'shared-shadowtls-node-password';
        $gecko = ['type' => 'hysteria', 'name' => 'gecko', 'host' => 'node.example.com', 'port' => 443, 'server_port' => 443, 'rate' => 1,
            'protocol_settings' => ['version' => 2, 'obfs' => ['open' => true, 'type' => 'gecko', 'password' => 'obfs-secret'], 'tls' => ['server_name' => 'node.example.com']]];
        foreach ([
            ['sing-box 1.12.0', 'sing-box/1.14.4', false, false],
            ['sing-box/1.14.0-alpha.25', 'sing-box/1.14.4', false, false],
            ['sing-box/1.14.0-alpha.26', 'sing-box', false, true],
            ['sing-box/1.14.0-alpha.38', 'sing-box/1.14.4', false, true],
            ['SFM (sing-box v1.14.0-alpha.38+build)', 'sing-box', false, true],
            ['sing-box/1.14.3', 'sing-box', false, true],
            ['sing-box/1.14.4-rc.1', 'sing-box', false, true],
            ['sing-box 1.14.4', 'sing-box/1.12.0', true, true],
            ['sing-box/1.14.4', 'sing-box', true, true],
            ['SFM (sing-box v1.14.5)', 'sing-box', true, true],
            ['', 'sing-box', false, false],
            ['', 'sing-box/1.14.0-alpha.38', false, true],
            ['', 'sing-box/1.14.4-rc.1', false, true],
            ['', 'sing-box/1.14.4', true, true],
        ] as [$ua, $flag, $supportsSnell, $supportsGecko]) {
            $request = Request::create('/api/v1/client/subscribe', 'GET', ['flag' => $flag], [], [], ['HTTP_USER_AGENT' => $ua]);
            $response = app(ClientController::class)->doSubscribe($request, $user, [$node, $wrapped, $gecko]);
            $config = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $snell = array_values(array_filter($config['outbounds'], fn($out) => $out['type'] === 'snell'));
            $shadowTls = array_values(array_filter($config['outbounds'], fn($out) => $out['type'] === 'shadowtls'));
            $this->assertCount($supportsSnell ? 2 : 0, $snell, $ua . ' / ' . $flag);
            $this->assertCount($supportsSnell ? 1 : 0, $shadowTls, $ua . ' / ' . $flag);
            if ($supportsSnell) {
                $this->assertSame(self::UUID, $snell[0]['psk']);
                $this->assertSame($shadowTls[0]['tag'], $snell[1]['detour']);
            }
            $hysteria = array_values(array_filter($config['outbounds'], fn($out) => $out['type'] === 'hysteria2'));
            $this->assertCount($supportsGecko ? 1 : 0, $hysteria, 'gecko: ' . $ua . ' / ' . $flag);
            if ($supportsGecko) {
                $this->assertSame('gecko', $hysteria[0]['obfs']['type']);
            }
        }
    }

    public function test_credentials_the_form_hides_are_not_length_checked(): void
    {
        // Independent PSKs hide the shared PSK, and the ShadowTLS password while ShadowTLS is off.
        $user = $this->snell(5, ['enabled' => false, 'password' => 'short']);
        $user['protocol_settings']['psk_mode'] = 'user';
        $user['protocol_settings']['psk'] = 'short';
        $this->assertTrue($this->passes($user));

        // A shared PSK hides the ShadowTLS password.
        $shared = $this->behindShadowTls(5);
        $shared['protocol_settings']['shadow_tls']['password'] = 'short';
        $this->assertTrue($this->passes($shared));

        // The credentials in use are still checked.
        $shared['protocol_settings']['psk'] = 'short';
        $this->assertFalse($this->passes($shared));
        unset($shared['protocol_settings']['psk']);
        $this->assertFalse($this->passes($shared));
        $user['protocol_settings']['shadow_tls'] = ['enabled' => true, 'server' => 'www.example.com', 'password' => 'short'];
        $this->assertFalse($this->passes($user));
    }

}
