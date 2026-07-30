<?php

namespace Tests\Feature;

use App\Jobs\ProcessEbioWebhookJob;
use App\Models\Device;
use App\Models\Organisation;
use App\Models\AttendanceLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProcessEbioWebhookJobTest extends TestCase
{
    public function tearDown(): void
    {
        tenancy()->end();
        // Clean up physically created databases
        foreach (Organisation::all() as $org) {
            $org->delete();
        }
        parent::tearDown();
    }

    public function test_job_processes_webhook_payload_and_creates_logs()
    {
        $organisation = Organisation::create([
            'name' => 'Test Org',
            'db_name' => 'test_org_job_1',
        ]);

        $logsPayload = [
            [
                'EmployeeCode' => '1001',
                'LogDate' => '2023-10-10 10:00:00',
                'SerialNumber' => 'DEV123',
                'DeviceName' => 'Front Door',
                'Direction' => 'IN',
                'VerificationType' => 'Face'
            ],
            [
                'EmployeeCode' => '1002',
                'LogDate' => '2023-10-10 10:05:00',
                'SerialNumber' => 'DEV123',
                'Direction' => 'OUT',
                'VerificationType' => 'Fingerprint'
            ]
        ];

        $job = new ProcessEbioWebhookJob($organisation, $logsPayload);
        $job->handle();

        // Need to assert within the tenant context
        tenancy()->initialize($organisation);

        $this->assertDatabaseHas('devices', [
            'serial_number' => 'DEV123',
            'name' => 'Front Door',
            'status' => 'online',
        ]);

        $device = Device::where('serial_number', 'DEV123')->first();

        $this->assertDatabaseHas('attendance_logs', [
            'pin' => '1001',
            'device_id' => $device->id,
            'status' => 0, // IN
            'verify_type' => 15, // Face
        ]);

        $this->assertDatabaseHas('attendance_logs', [
            'pin' => '1002',
            'device_id' => $device->id,
            'status' => 1, // OUT
            'verify_type' => 1, // Fingerprint (default)
        ]);
    }

    public function test_job_ignores_duplicate_punches_without_failing()
    {
        $organisation = Organisation::create([
            'name' => 'Test Org 2',
            'db_name' => 'test_org_job_2',
        ]);

        $logsPayload = [
            [
                'EmployeeCode' => '1001',
                'LogDate' => '2023-10-10 10:00:00',
                'SerialNumber' => 'DEV123',
            ],
            [
                'EmployeeCode' => '1001',
                'LogDate' => '2023-10-10 10:00:00', // Duplicate!
                'SerialNumber' => 'DEV123',
            ],
            [
                'EmployeeCode' => '1003',
                'LogDate' => '2023-10-10 10:10:00', // Valid punch after duplicate
                'SerialNumber' => 'DEV123',
            ]
        ];

        $job = new ProcessEbioWebhookJob($organisation, $logsPayload);
        $job->handle(); // Should not throw exception

        tenancy()->initialize($organisation);

        $this->assertDatabaseCount('attendance_logs', 2);
        
        $this->assertDatabaseHas('attendance_logs', [
            'pin' => '1001',
        ]);
        
        $this->assertDatabaseHas('attendance_logs', [
            'pin' => '1003',
        ]);
    }
}
