<?php

namespace Tests\Feature;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Organisation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EbioWebhookControllerTest extends TestCase
{
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

        $organisation = Organisation::create([
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

        $response = $this->postJson("/api/ebio/webhook/{$organisation->id}", $payload);

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

        $organisation = Organisation::create([
            'name' => 'Test Org 2',
            'db_name' => 'test_org_controller_2',
        ]);
        $this->organisations[] = $organisation;

        $payload = [
            'EmployeeCode' => '1002',
            'LogDate' => '2023-10-10 10:05:00',
        ];

        $response = $this->postJson("/api/ebio/webhook/{$organisation->id}", $payload);

        $response->assertStatus(200)
            ->assertSee('Success');

        Queue::assertPushed(ProcessEbioWebhookJob::class, function ($job) {
            return count($job->logs) === 1 && $job->logs[0]['EmployeeCode'] === '1002';
        });
    }
}
