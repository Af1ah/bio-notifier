<?php

namespace Tests\Feature;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Device;
use App\Models\Organisation;
use App\Models\WebhookReceipt;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EbioWebhookControllerTest extends TestCase
{
    public function test_receipts_are_encrypted_deduplicated_and_recoverable_after_device_rejection(): void
    {
        Queue::fake();
        config(['services.ebio.allow_device_discovery' => false]);
        $org = Organisation::create(['device_integration' => 'ebio', 'name' => 'Receipt test']);
        $this->organisations[] = $org;
        $payload = [['EmployeeCode' => 'receipt-pin', 'SerialNumber' => 'REGISTER-ME', 'LogDate' => '2026-09-01 09:00:00']];
        $url = '/api/ebio/webhook/'.$org->ebio_webhook_token;
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        Queue::assertPushed(ProcessEbioWebhookJob::class, 1);
        $receipt = WebhookReceipt::firstOrFail();
        $this->assertStringNotContainsString('receipt-pin', $receipt->getRawOriginal('payload'));
        $job = new ProcessEbioWebhookJob($org, [], $receipt->id);
        try {
            $job->handle();
            $this->fail('An unknown device must be rejected in strict mode.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Unregistered', $exception->getMessage());
        }
        $this->assertFalse(tenancy()->initialized);
        $this->assertSame('failed', $receipt->fresh()->state);
        tenancy()->initialize($org);
        Device::create(['serial_number' => 'REGISTER-ME']);
        tenancy()->end();
        $this->artisan('ebio:replay-receipts', ['--failed' => true])->assertSuccessful();
        $job->handle();
        $this->assertSame('processed', $receipt->fresh()->state);
        $this->assertSame([], $receipt->fresh()->payload);
        tenancy()->initialize($org);
        $this->assertDatabaseCount('attendance_logs', 1);
        $this->assertDatabaseHas('attendance_logs', ['pin' => 'receipt-pin']);
    }

    public function test_missing_serial_uses_a_real_tenant_device_not_invalid_foreign_key_zero(): void
    {
        $org = Organisation::create(['device_integration' => 'ebio', 'name' => 'No serial']);
        $this->organisations[] = $org;
        (new ProcessEbioWebhookJob($org, [['EmployeeCode' => 'no-serial', 'LogDate' => '2026-09-01 09:00:00']]))->handle();
        tenancy()->initialize($org);
        $device = Device::where('serial_number', 'ebio-unidentified')->firstOrFail();
        $this->assertDatabaseHas('attendance_logs', ['pin' => 'no-serial', 'device_id' => $device->id]);
    }

    private array $organisations = [];

    protected function setUp(): void
    {
        parent::setUp();
        Artisan::call('migrate:fresh', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        tenancy()->end();
        foreach ($this->organisations as $org) {
            $org->delete();
        }
        parent::tearDown();
    }

    public function test_webhook_returns_403_for_invalid_token()
    {
        $response = $this->post('/api/ebio/webhook/invalid-token', []);

        $response->assertStatus(403)
            ->assertSee('Invalid Token');
    }

    public function test_webhook_dispatches_job_and_returns_success_for_valid_payload()
    {
        Queue::fake();

        $organisation = Organisation::create(['device_integration' => 'ebio',
            'name' => 'Test Org',
            'db_name' => 'test_org_controller_1',
        ]);
        $this->organisations[] = $organisation;

        $payload = [
            [
                'EmployeeCode' => '1001',
                'LogDate' => '2023-10-10 10:00:00',
                'SerialNumber' => 'DEV123',
                'Direction' => 'IN',
                'VerificationType' => 'Fingerprint',
            ],
        ];

        $response = $this->postJson("/api/ebio/webhook/{$organisation->ebio_webhook_token}", $payload);

        $response->assertStatus(200)
            ->assertSee('Success')
            ->assertHeader('Content-Type', 'text/plain; charset=UTF-8');

        Queue::assertPushed(ProcessEbioWebhookJob::class, function ($job) use ($organisation) {
            return $job->organisation->id === $organisation->id;
        });
    }

    public function test_webhook_handles_single_object_payload()
    {
        Queue::fake();

        $organisation = Organisation::create(['device_integration' => 'ebio',
            'name' => 'Test Org 2',
            'db_name' => 'test_org_controller_2',
        ]);
        $this->organisations[] = $organisation;

        $payload = [
            'EmployeeCode' => '1002',
            'LogDate' => '2023-10-10 10:05:00',
        ];

        $response = $this->postJson("/api/ebio/webhook/{$organisation->ebio_webhook_token}", $payload);

        $response->assertStatus(200)
            ->assertSee('Success');

        Queue::assertPushed(ProcessEbioWebhookJob::class, function ($job) {
            return $job->logs === [] && WebhookReceipt::findOrFail($job->receiptId)->payload[0]['EmployeeCode'] === '1002';
        });
    }
}
