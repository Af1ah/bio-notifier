<?php

namespace App\Jobs;

use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Organisation;
use App\Models\WebhookReceipt;
use App\Services\Attendance\AttendanceReplayReconciler;
use App\Services\Devices\DeviceIntegration;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class ProcessEbioWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $organisation;

    public $logs;

    public int $tries = 3;

    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(Organisation $organisation, array $logs, public ?int $receiptId = null)
    {
        $this->organisation = $organisation;
        $this->logs = $logs;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $receipt = $this->receiptId ? WebhookReceipt::findOrFail($this->receiptId) : null;
        if ($receipt) {
            if ($receipt->state === 'processed') {
                return;
            }
            if ((string) $receipt->organisation_id !== (string) $this->organisation->id) {
                throw new \RuntimeException('Receipt tenant mismatch.');
            }
            if (! WebhookReceipt::whereKey($receipt->id)->whereIn('state', ['pending', 'queued', 'failed'])->update(['state' => 'processing', 'updated_at' => now()])) {
                return;
            }
            $receipt->increment('attempts');
            $receipt->update(['state' => 'processing', 'last_error' => null]);
        }
        $previous = tenant();
        try {
            if ($receipt) {
                $this->logs = $receipt->payload;
            }
            DeviceIntegration::requireEbio($this->organisation);
            tenancy()->initialize($this->organisation);
            $reconciler = new AttendanceReplayReconciler;
            foreach ($this->logs as $logData) {
                if (! isset($logData['EmployeeCode']) || ! isset($logData['LogDate'])) {
                    continue;
                }

                try {
                    // Lookup or create device
                    $serial = filled($logData['SerialNumber'] ?? null) ? $logData['SerialNumber'] : 'ebio-unidentified';
                    if (! config('services.ebio.allow_device_discovery', true) && ! Device::where('serial_number', $serial)->exists()) {
                        throw new \RuntimeException('Unregistered webhook device. Provision the device before replay.');
                    }
                    $device = null;
                    if ($serial) {
                        $device = Device::firstOrCreate(
                            ['serial_number' => $serial],
                            ['name' => $logData['DeviceName'] ?? 'Unknown Device']
                        );

                        // Update the last ping time because the device just communicated with us
                        $device->update([
                            'status' => 'online',
                            'last_activity_at' => now(),
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
                    $log = AttendanceLog::firstOrCreate([
                        'pin' => $logData['EmployeeCode'],
                        'punched_at' => Carbon::parse($logData['LogDate']),
                    ], [
                        'device_id' => $device ? $device->id : 0,
                        'status' => $status,
                        'verify_type' => $verifyType,
                        'work_code' => isset($logData['WorkCode']) ? (int) $logData['WorkCode'] : null,
                        'raw_data' => $logData,
                    ]);
                    if (! $log->wasRecentlyCreated) {
                        $reconciler->recoverMissingDay($log);
                    }
                } catch (QueryException $e) {
                    throw $e;
                } catch (\Exception $e) {
                    throw $e;
                }
            }
            $receipt?->update(['state' => 'processed', 'payload' => [], 'last_error' => null]);
        } catch (\Throwable $exception) {
            $receipt?->update(['state' => 'failed', 'last_error' => 'Processing failed; inspect failed queue jobs before replay.']);
            throw $exception;
        } finally {
            tenancy()->end();
            if ($previous) {
                tenancy()->initialize($previous);
            }
        }
    }
}
