<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\AttendanceLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessEbioWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;
    public $logs;

    /**
     * Create a new job instance.
     */
    public function __construct(Organisation $organisation, array $logs)
    {
        $this->organisation = $organisation;
        $this->logs = $logs;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        tenancy()->initialize($this->organisation);

        foreach ($this->logs as $logData) {
            if (!isset($logData['EmployeeCode']) || !isset($logData['LogDate'])) {
                continue;
            }

            try {
                // Lookup or create device
                $device = null;
                if (isset($logData['SerialNumber'])) {
                    $device = \App\Models\Device::firstOrCreate(
                        ['serial_number' => $logData['SerialNumber']],
                        ['name' => $logData['DeviceName'] ?? 'Unknown Device']
                    );
                    
                    // Update the last ping time because the device just communicated with us
                    $device->update([
                        'status' => 'online',
                        'last_activity_at' => now()
                    ]);
                }

                // Map Status (Direction)
                $status = 0; // Default Check In
                if (isset($logData['Direction'])) {
                    $dir = strtolower($logData['Direction']);
                    if (str_contains($dir, 'out')) {
                        $status = 1;
                    }
                }

                // Map Verify Type
                $verifyType = 1; // Default Fingerprint
                if (isset($logData['VerificationType'])) {
                    $vType = strtolower($logData['VerificationType']);
                    if (str_contains($vType, 'face')) {
                        $verifyType = 15;
                    } elseif (str_contains($vType, 'card')) {
                        $verifyType = 2;
                    } elseif (str_contains($vType, 'password') || str_contains($vType, 'pin')) {
                        $verifyType = 0;
                    }
                }

                // Save to tenant's AttendanceLog table
                AttendanceLog::updateOrCreate([
                    'pin' => $logData['EmployeeCode'],
                    'punched_at' => \Illuminate\Support\Carbon::parse($logData['LogDate']),
                ], [
                    'device_id' => $device ? $device->id : 0,
                    'status' => $status,
                    'verify_type' => $verifyType,
                    'work_code' => isset($logData['WorkCode']) ? (int) $logData['WorkCode'] : null,
                    'raw_data' => $logData,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Catch unique constraint violations quietly (duplicate punches)
                if ($e->getCode() !== '23505' && $e->getCode() !== '23000') {
                    Log::warning("Database error processing webhook punch for {$logData['EmployeeCode']}: " . $e->getMessage());
                }
            } catch (\Exception $e) {
                Log::error("Failed to process webhook punch for {$logData['EmployeeCode']}: " . $e->getMessage());
            }
        }
    }
}
