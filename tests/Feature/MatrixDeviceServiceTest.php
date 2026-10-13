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
    public function test_internal_card_reader_update_preserves_other_reader_and_access_settings(): void
    {
        Http::fakeSequence()->push('<COSEC_API><Response-Code>0</Response-Code></COSEC_API>')
            ->push('<COSEC_API><reader1>4</reader1><reader2>1</reader2><reader3>2</reader3><door-access-mode>6</door-access-mode></COSEC_API>');
        $device = new Device(['vendor'=>'matrix','ip_address'=>'192.0.2.10','username'=>'admin','password'=>'secret','port'=>80]);
        $result = app(MatrixDeviceService::class)->setInternalCardReader($device, 4);
        $this->assertTrue($result['success']);
        Http::assertSent(function (Request $request): bool {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            return ($query['action'] ?? '') === 'set' && ($query['reader1'] ?? '') === '4'
                && count($query) === 3 && ($query['format'] ?? '') === 'xml';
        });
        Http::assertSentCount(2);
    }
    public function test_unconfirmed_reader_update_does_not_report_success(): void
    {
        Http::fakeSequence()->push('<COSEC_API><Response-Code>0</Response-Code></COSEC_API>')
            ->push('<COSEC_API><reader1>1</reader1></COSEC_API>');
        $device = new Device(['vendor'=>'matrix','ip_address'=>'192.0.2.10','username'=>'admin','password'=>'secret','port'=>80]);
        $result = app(MatrixDeviceService::class)->setInternalCardReader($device, 4);
        $this->assertFalse($result['success']);$this->assertTrue($result['outcome_unknown']);
    }
    public function test_invalid_reader_type_cannot_be_sent(): void
    {
        Http::fake();$device = new Device(['vendor'=>'matrix','ip_address'=>'192.0.2.10']);
        try { app(MatrixDeviceService::class)->setInternalCardReader($device, 99);$this->fail('Invalid type accepted'); }
        catch (\Illuminate\Validation\ValidationException $e) { Http::assertNothingSent(); }
    }

    public function test_internal_reader_action_requires_a_matrix_device_and_admin(): void
    {
        $user=(new \App\Models\User())->forceFill(['id'=>42,'privilege'=>14,'role'=>'admin']);$this->actingAs($user);
        $action=\App\Filament\Tenant\Resources\DeviceResource\Actions\ConfigureInternalReader::make()->record(new Device(['vendor'=>'matrix']));
        $this->assertTrue($action->isVisible());$this->assertTrue($action->isAuthorized());
        $action->record(new Device(['vendor'=>'zkteco']));$this->assertFalse($action->isVisible());$this->assertFalse($action->isAuthorized());
        $user->forceFill(['privilege'=>1,'role'=>'user']);$action->record(new Device(['vendor'=>'matrix']));
        $this->assertFalse($action->isVisible());$this->assertFalse($action->isAuthorized());
    }

}
