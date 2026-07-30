<?php

namespace Tests\Feature;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Organisation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EbioWebhookControllerTest extends TestCase
{
    public function tearDown(): void
    {
        // Clean up physically created databases
        foreach (Organisation::all() as $org) {
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

        $organisation = Organisation::withoutEvents(function () {
            return Organisation::create([
                'id' => '0c93ecb4-b72a-4528-b12d-659dc44693c0',
                'name' => 'Test Org',
                'db_name' => 'test_org',
            ]);
        });

        $payload = [
            [
                'EmployeeCode' => '1001',
                'LogDate' => '2023-10-10 10:00:00',
                'SerialNumber' => 'DEV123',
                'Direction' => 'IN',
                'VerificationType' => 'Fingerprint'
            ]
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

        $organisation = Organisation::withoutEvents(function () {
            return Organisation::create([
                'id' => '0c93ecb4-b72a-4528-b12d-659dc44693c1',
                'name' => 'Test Org 2',
                'db_name' => 'test_org_2',
            ]);
        });

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
