<?php

namespace App\Jobs;

use App\Models\AdmsPayload;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\Organisation;
use App\Models\User;
use App\Services\Attendance\AdmsRequestParser;
use App\Events\AttendanceReceived;
use App\Events\UserSynced;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessAdmsPayload implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public AdmsPayload $payload)
    {
    }

    public function handle(AdmsRequestParser $parser): void
    {
        try {
            if ($this->payload->tenant_id) {
                $tenant = Organisation::find($this->payload->tenant_id);
                if ($tenant) {
                    tenancy()->initialize($tenant);
                }
            }

            $deviceModel = config('zkteco-adms.models.device', Device::class);
            $device = $deviceModel::where('serial_number', $this->payload->serial_number)->first();

            if (! $device) {
                throw new \Exception("Device {$this->payload->serial_number} not found in tenant database.");
            }

            $table = strtoupper($this->payload->table_name);
            $body = $this->payload->payload;
            $stamp = $this->payload->stamp;

            switch ($table) {
                case 'ATTLOG':
                    $this->processAttendanceLogs($device, $body, $stamp, $parser);
                    break;
                case 'OPERLOG':
                    $this->processOperationLogs($device, $body, $stamp, $parser);
                    break;
                case 'OPTIONS':
                    $this->processOptions($device, $body, $parser);
                    break;
            }

            $this->payload->delete();

        } catch (\Exception $e) {
            Log::error("Failed to process ADMS Payload: " . $e->getMessage());
            $this->payload->update([
                'status' => 'failed',
                'error_message' => $e->getMessage() . "\n" . $e->getTraceAsString(),
            ]);
        } finally {
            if (function_exists('tenancy') && tenancy()->initialized) {
                tenancy()->end();
            }
        }
    }

    protected function processAttendanceLogs(Device $device, string $body, ?string $stamp, AdmsRequestParser $parser): void
    {
        $logs = $parser->parseAttendanceLogs($body);
        $modelClass = config('zkteco-adms.models.attendance_log', AttendanceLog::class);

        foreach ($logs as $log) {
            $status = $log['status'];

            if ($device->punch_behavior === 'always_in') {
                $status = 0; // Check In
            } elseif ($device->punch_behavior === 'always_out') {
                $status = 1; // Check Out
            } elseif ($device->punch_behavior === 'auto') {
                $lastLog = $modelClass::where('pin', $log['pin'])
                    ->whereDate('punched_at', \Carbon\Carbon::parse($log['punched_at'])->toDateString())
                    ->orderBy('punched_at', 'desc')
                    ->first();
                
                $status = ($lastLog && $lastLog->status === 0) ? 1 : 0;
            }

            $record = $modelClass::firstOrCreate(
                [
                    'pin' => $log['pin'],
                    'punched_at' => $log['punched_at'],
                ],
                [
                    'device_id' => $device->id,
                    'status' => $status,
                    'verify_type' => $log['verify_type'],
                    'work_code' => $log['work_code'],
                    'reserved_1' => $log['reserved_1'],
                    'reserved_2' => $log['reserved_2'],
                    'raw_data' => ['raw' => $log['raw']],
                ]
            );

            if ($record->wasRecentlyCreated && config('zkteco-adms.events.dispatch_attendance_received', true)) {
                event(new AttendanceReceived($record, $device));
            }
        }

        if ($stamp) {
            $device->update(['att_stamp' => max($device->att_stamp, (int) $stamp)]);
        }

        $device->update(['last_sync_at' => now()]);
    }

    protected function processOperationLogs(Device $device, string $body, ?string $stamp, AdmsRequestParser $parser): void
    {
        $operations = $parser->parseOperationLogs($body);
        $userModel = config('zkteco-adms.models.user', User::class);

        foreach ($operations as $op) {
            if ($op['type'] === 'user' && isset($op['pin'])) {
                $updateData = [];
                if (array_key_exists('name', $op)) $updateData['name'] = $op['name'];
                if (array_key_exists('card', $op)) $updateData['card_number'] = $op['card'];
                if (array_key_exists('privilege', $op) || array_key_exists('pri', $op)) $updateData['privilege'] = (int) ($op['privilege'] ?? $op['pri'] ?? 0);
                if (array_key_exists('password', $op) || array_key_exists('passwd', $op)) $updateData['device_password'] = $op['password'] ?? $op['passwd'];
                if (array_key_exists('group', $op) || array_key_exists('grp', $op)) $updateData['group'] = $op['group'] ?? $op['grp'];

                $user = $userModel::updateOrCreate(
                    ['pin' => $op['pin']],
                    $updateData
                );

                if (config('zkteco-adms.events.dispatch_user_synced', true)) {
                    event(new UserSynced($user, $device));
                }
            }

            if ($op['type'] === 'user_update_needed' && isset($op['pin'])) {
                app(\App\Services\Attendance\DeviceCommandBuilder::class)->queryUser($device, $op['pin']);
            }

            if ($op['type'] === 'fingerprint' && isset($op['pin'])) {
                $user = $userModel::where('pin', $op['pin'])->first();

                if ($user) {
                    $fingerprints = $user->fingerprints ?? [];
                    $fingerprints[$op['fid'] ?? 0] = [
                        'size' => $op['size'] ?? null,
                        'valid' => $op['valid'] ?? null,
                        'tmp' => $op['tmp'] ?? null,
                    ];
                    $user->update(['fingerprints' => $fingerprints]);
                }
            }

            if ($op['type'] === 'face' && isset($op['pin'])) {
                $user = $userModel::where('pin', $op['pin'])->first();

                if ($user) {
                    $faceTemplates = $user->face_templates ?? [];
                    $faceTemplates[$op['fid'] ?? 0] = [
                        'size' => $op['size'] ?? null,
                        'valid' => $op['valid'] ?? null,
                        'tmp' => $op['tmp'] ?? null,
                    ];
                    $user->update(['face_templates' => $faceTemplates]);
                }
            }
        }

        if ($stamp) {
            $device->update(['op_stamp' => max($device->op_stamp, (int) $stamp)]);
        }
    }

    protected function processOptions(Device $device, string $body, AdmsRequestParser $parser): void
    {
        $options = $parser->parseOptions($body);

        if (! empty($options)) {
            $device->update(['options' => array_merge($device->options ?? [], $options)]);
        }
    }
}
