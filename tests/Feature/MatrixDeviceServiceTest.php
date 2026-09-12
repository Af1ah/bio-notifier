<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Services\Attendance\MatrixDeviceService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MatrixDeviceServiceTest extends TestCase
{
    public function test_it_retrieves_editable_matrix_user_fields(): void
    {
        Http::fake([
            '*' => Http::response(<<<'XML'
<COSEC_API>
    <user-id>11</user-id>
    <ref-user-id>100011</ref-user-id>
    <name>Test User</name>
    <user-active>1</user-active>
    <vip>0</vip>
    <card1>15192047291401264</card1>
    <card2>386548900517</card2>
</COSEC_API>
XML),
        ]);

        $device = new Device([
            'ip_address' => '192.0.2.10',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 80,
            'protocol' => 'http',
        ]);

        $result = app(MatrixDeviceService::class)->getUser($device, '11');

        $this->assertTrue($result['success']);
        $this->assertSame('100011', $result['user']['reference_id']);
        $this->assertTrue($result['user']['user_active']);
        $this->assertSame('15192047291401264', $result['user']['card1']);
        $this->assertSame('386548900517', $result['user']['card2']);
    }

    public function test_it_sends_supported_user_fields_and_omits_restrict_access(): void
    {
        Http::fake(['*' => Http::response('<COSEC_API><Response-Code>0</Response-Code></COSEC_API>')]);

        $device = new Device([
            'ip_address' => '192.0.2.10',
            'username' => 'admin',
            'password' => 'secret',
            'port' => 80,
            'protocol' => 'http',
        ]);

        $result = app(MatrixDeviceService::class)->setUser($device, [
            'user_id' => '11',
            'reference_id' => '100011',
            'name' => 'Test User',
            'user_active' => false,
            'vip' => true,
            'restrict_access' => true,
            'card1' => '',
            'card2' => '386548900517',
        ]);

        $this->assertTrue($result['success']);

        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return $query['action'] === 'set'
                && $query['user-id'] === '11'
                && $query['ref-user-id'] === '100011'
                && $query['user-active'] === '0'
                && $query['vip'] === '1'
                && ! array_key_exists('restrict-access', $query)
                && $query['card1'] === '0'
                && $query['card2'] === '386548900517';
        });
    }
}
