<?php

namespace Tests\Feature\Server;

use App\Http\Requests\Admin\ServerSave;
use App\Models\Server;
use App\Protocols\General;
use App\Protocols\SingBox;
use App\Services\ServerService;
use Illuminate\Support\Facades\Validator;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class SnellGeckoTest extends TestCase
{
    private const UUID = '11111111-1111-4111-8111-111111111111';
    private const PSK = '0123456789abcdef';

    private function nodeData(string $type, array $settings): array
    {
        return [
            'type' => $type, 'name' => $type, 'host' => 'node.example.com', 'port' => 443,
            'server_port' => 443, 'rate' => 1, 'protocol_settings' => $settings,
        ];
    }

    private function hysteria(string $obfsType, string $name = 'hy2'): array
    {
        return ['name' => $name] + $this->nodeData('hysteria', [
            'version' => 2,
            'obfs' => ['open' => true, 'type' => $obfsType, 'password' => 'obfs-secret'],
            'tls' => ['server_name' => 'node.example.com'],
            'bandwidth' => ['up' => 10, 'down' => 100],
        ]);
    }

    private function snell(int $version): array
    {
        return $this->nodeData('snell', [
            'version' => $version, 'psk' => self::PSK, 'obfs_mode' => 'http', 'mode' => 'unshaped',
        ]);
    }

    private function passes(array $data): bool
    {
        return Validator::make($data, ServerSave::create('/test', 'POST', $data)->rules())->passes();
    }

    /** Outbounds the sing-box subscription builds for this client, keyed by tag. */
    private function singBoxOutbounds(array $servers, string $client, string $version): array
    {
        $protocol = new SingBox(['uuid' => self::UUID], $servers, $client, $version);
        $config = new ReflectionProperty(SingBox::class, 'config');
        $config->setValue($protocol, ['outbounds' => []]);
        (new ReflectionMethod(SingBox::class, 'buildOutbounds'))->invoke($protocol);
        return collect($config->getValue($protocol)['outbounds'])->keyBy('tag')->all();
    }

    public function test_admin_validation_accepts_gecko_and_snell(): void
    {
        $this->assertTrue($this->passes($this->hysteria('gecko')));
        $this->assertTrue($this->passes($this->hysteria('salamander')));
        $this->assertFalse($this->passes($this->hysteria('unknown')));

        $this->assertTrue($this->passes($this->snell(5)));
        $this->assertTrue($this->passes($this->snell(6)));
        $this->assertFalse($this->passes($this->snell(4)));
        $short = $this->snell(6);
        $short['protocol_settings']['psk'] = 'short';
        $this->assertFalse($this->passes($short));
        // The limit is in bytes: 100 CJK characters are 300 bytes, 6 are 18.
        $wide = $this->snell(6);
        $wide['protocol_settings']['psk'] = str_repeat('密', 100);
        $this->assertFalse($this->passes($wide));
        $wide['protocol_settings']['psk'] = str_repeat('密', 6);
        $this->assertTrue($this->passes($wide));
    }

    public function test_node_config_carries_gecko_and_snell_settings(): void
    {
        $gecko = ServerService::buildNodeConfig(new Server($this->hysteria('gecko')));
        $this->assertSame('gecko', $gecko['obfs']);
        $this->assertSame('obfs-secret', $gecko['obfs-password']);

        $v5 = ServerService::buildNodeConfig(new Server($this->snell(5)));
        $this->assertSame('snell', $v5['protocol']);
        $this->assertSame(5, $v5['version']);
        $this->assertSame(self::PSK, $v5['server_key']);
        $this->assertSame('http', $v5['obfs']);
        $this->assertArrayNotHasKey('mode', $v5);

        $v6 = ServerService::buildNodeConfig(new Server($this->snell(6)));
        $this->assertSame(6, $v6['version']);
        $this->assertSame('unshaped', $v6['mode']);
        $this->assertArrayNotHasKey('obfs', $v6);
    }

    public function test_only_sing_box_1_14_receives_snell_and_gecko_nodes(): void
    {
        $servers = [
            $this->hysteria('gecko', 'gecko'),
            $this->hysteria('salamander', 'salamander'),
            ['name' => 'snell5'] + $this->snell(5),
            ['name' => 'snell6'] + $this->snell(6),
        ];

        $current = $this->singBoxOutbounds($servers, 'sing-box', '1.14.0');
        $this->assertSame(['gecko', 'salamander', 'snell5', 'snell6'], array_keys($current));
        $this->assertSame('gecko', $current['gecko']['obfs']['type']);
        $this->assertSame(
            ['type' => 'snell', 'tag' => 'snell5', 'server' => 'node.example.com', 'server_port' => 443,
                'psk' => self::PSK, 'userkey' => self::UUID, 'version' => 4, 'obfs_mode' => 'http'],
            $current['snell5']
        );
        $this->assertSame(6, $current['snell6']['version']);
        $this->assertSame('unshaped', $current['snell6']['mode']);
        $this->assertArrayNotHasKey('obfs_mode', $current['snell6']);

        foreach ([['sing-box', '1.13.9'], ['hiddify', '2.5.7']] as [$client, $version]) {
            $this->assertSame(['salamander'], array_keys($this->singBoxOutbounds($servers, $client, $version)));
        }

        $general = new General(['uuid' => self::UUID], $servers);
        $kept = (new ReflectionProperty(General::class, 'servers'))->getValue($general);
        $this->assertSame(['salamander'], array_column($kept, 'name'));
    }

    public function test_gecko_type_is_ignored_while_obfs_is_off(): void
    {
        $server = $this->hysteria('gecko');
        $server['protocol_settings']['obfs']['open'] = false;
        $general = new General(['uuid' => self::UUID], [$server]);
        $this->assertCount(1, (new ReflectionProperty(General::class, 'servers'))->getValue($general));
    }
}
