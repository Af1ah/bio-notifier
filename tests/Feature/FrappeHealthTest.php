<?php

namespace Tests\Feature;

use App\Models\AttendanceLog;
use App\Models\FrappeSetting;
use App\Services\Integrations\FrappeCheckins;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FrappeHealthTest extends TestCase
{
    private function settings(): FrappeSetting
    {
        return new FrappeSetting(['hostname' => 'hr.example.com', 'api_key' => 'testkey', 'api_secret' => 'testsecret']);
    }

    public function test_health_verifies_authentication_and_maps_safe_error_messages(): void
    {
        Http::preventStrayRequests();
        Http::fakeSequence()
            ->push(['message' => 'api@example.com'])
            ->push(['message' => 'Guest'])
            ->push(['message' => ['invalid']])
            ->push('<html>Not Frappe</html>')
            ->push('', 302, ['Location' => 'http://127.0.0.1/'])
            ->push(['error' => 'private response details'], 401)
            ->push([], 403)->push([], 429)->push([], 503)->push([], 404);

        $service = app(FrappeCheckins::class);
        foreach (['healthy', 'unexpected_response', 'unexpected_response', 'unexpected_response', 'unexpected_response',
            'authentication', 'permission', 'rate_limited', 'server', 'unexpected_response'] as $expected) {
            $this->assertSame($expected, $service->health($this->settings(), '93.184.216.34'));
        }
        Http::assertSentCount(10);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && $request->url() === 'https://hr.example.com/api/method/frappe.auth.get_logged_user'
            && $request->hasHeader('Authorization', 'token testkey:testsecret'));
    }

    public function test_health_rejects_unsafe_destinations_and_missing_credentials_without_requests(): void
    {
        Http::fake();
        $service = app(FrappeCheckins::class);
        foreach ([null, '127.0.0.1', '192.168.1.1', '169.254.169.254', '224.0.0.1'] as $address) {
            $this->assertSame('destination', $service->health($this->settings(), $address));
        }
        $settings = $this->settings();
        $settings->api_key = null;
        $this->assertSame('authentication', $service->health($settings, '93.184.216.34'));
        $settings->setRawAttributes(['hostname' => 'hr.example.com', 'api_key' => 'broken-encrypted-value']);
        $this->assertSame('authentication', $service->health($settings, '93.184.216.34'));
        Http::assertNothingSent();
    }

    public function test_health_handles_connection_failure_and_pins_https_requests(): void
    {
        Http::fake(function (Request $request, array $options) {
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['verify']);
            $this->assertSame(7, $options['timeout']);
            $this->assertSame(['hr.example.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            throw new ConnectionException('Connection failed');
        });
        $this->assertSame('connection', app(FrappeCheckins::class)->health($this->settings(), '93.184.216.34'));
    }

    public function test_delivery_errors_explain_employee_mapping_and_required_direction(): void
    {
        $responses = Http::fakeSequence();
        foreach ([
            "No Employee found for the given employee field value. 'attendance_device_id': 0042",
            'Log Type is required for check-ins falling in the shift: General',
            'Other private server validation details',
        ] as $message) {
            $responses->push(['exc_type' => 'ValidationError', '_server_messages' => json_encode([
                json_encode(['message' => $message]),
            ])], 417);
        }
        $log = new AttendanceLog(['pin' => '0042', 'device_id' => 1, 'punched_at' => '2020-01-01 09:00:00', 'status' => 0]);
        foreach (['employee_not_found', 'log_type_required', 'http_417'] as $error) {
            $result = app(FrappeCheckins::class)->push($this->settings(), $log, '93.184.216.34');
            $this->assertSame($error, $result->error);
            $this->assertFalse($result->retryable());
        }
    }
}
