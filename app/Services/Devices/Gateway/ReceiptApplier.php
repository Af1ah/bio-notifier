<?php

namespace App\Services\Devices\Gateway;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Device;
use App\Models\DeviceCommand;
use App\Models\GatewayCommandSubmission;
use App\Models\GatewayIngressReceipt;
use App\Models\User;
use App\Services\Payroll\PayrollFreshnessService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ReceiptApplier
{
    public function apply(GatewayIngressReceipt $receipt): void
    {
        $payload = $receipt->payload;
        DB::transaction(function () use ($receipt, $payload) {
            if (! DB::table('gateway_applied_receipts')->insertOrIgnore(['id' => $receipt->id, 'applied_at' => now()])) {
                return;
            }
            // Match payroll/calculation lock order before changing attendance evidence.
            $employees = collect();
            if ($receipt->kind === 'attendance') {
                $employees = User::whereIn('pin', array_column($payload['punches'], 'pin'))->orderBy('id')->lockForUpdate()->get()->keyBy('pin');
            }
            $device = Device::whereKey($receipt->device_id)->where('serial_number', $payload['serial'])->lockForUpdate()->firstOrFail();
            $received = Carbon::parse($payload['received_at']);
            if (! $device->last_activity_at || $received->gt($device->last_activity_at)) {
                $device->update(['last_activity_at' => $received, 'status' => 'online']);
            }
            if ($receipt->kind === 'attendance') {
                foreach ($payload['punches'] as $index => $punch) {
                    // The legacy application stores tenant-local wall time. Keep the original offset as evidence.
                    $at = Carbon::parse($punch['at'])->setTimezone(config('attendance.timezone', 'Asia/Kolkata'));
                    $log = AttendanceLog::withoutEvents(fn () => AttendanceLog::firstOrCreate([
                        'pin' => $punch['pin'], 'punched_at' => $at->format('Y-m-d H:i:s'),
                    ], [
                        'device_id' => $device->id, 'status' => $punch['status'], 'verify_type' => $punch['verify'],
                        'work_code' => $punch['work_code'] ?? null, 'raw_data' => ['source' => 'device', 'receipt_id' => $receipt->id, 'original_time' => $punch['at']],
                    ]));
                    DB::table('gateway_attendance_evidence')->insert([
                        'receipt_id' => $receipt->id, 'record_index' => $index, 'attendance_log_id' => $log->id,
                        'serial' => $payload['serial'], 'pin' => $punch['pin'], 'original_time' => $punch['at'],
                        'raw_status' => $punch['status'], 'raw_verify' => $punch['verify'], 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    if ($log->wasRecentlyCreated) {
                        $employee = $employees->get($punch['pin']);
                        if ($employee) {
                            // Reuse the already-loaded employee/device; no extra lookup per punch.
                            $format = match ((int) $punch['verify']) { 1 => 'finger', 15 => 'face', default => null };
                            $templates = $format === 'finger' ? $employee->fingerprints : $employee->face_templates;
                            $stored = is_array($templates) && collect($templates)->contains(fn ($template) => is_array($template) && filled($template['tmp'] ?? null));
                            $requests = $employee->biometric_fetch_requests ?? [];
                            if ($format && ! $stored && ! isset($requests[$format]) && $device->supportsVerificationFormat($format)) {
                                $requests[$format] = ['command_id' => (string) \Illuminate\Support\Str::uuid(), 'device_id' => $device->id];
                                $employee->update(['biometric_fetch_requests' => $requests]);
                                DB::table('gateway_domain_effects')->insert([
                                    'receipt_id' => $receipt->id, 'attendance_log_id' => $log->id,
                                    'type' => 'biometric_'.$format, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                                ]);
                            }
                            $dates = [$at->copy()->subDay()->toDateString(), $at->toDateString()];
                            $days = AttendanceDay::where('user_id', $employee->id)->where(function ($query) use ($dates, $at) {
                                $query->where(function ($datesQuery) use ($dates) {
                                    foreach ($dates as $date) $datesQuery->orWhereDate('work_date', $date);
                                })->orWhereHas('occurrences', fn ($q) => $q->where('window_starts_at', '<=', $at)->where('window_ends_at', '>=', $at));
                            })->orderBy('id')->lockForUpdate()->get();
                            foreach ($days as $day) {
                                $day->update(['stale_at' => now()]);
                                $dates[] = $day->work_date->toDateString();
                            }
                            foreach (array_unique($dates) as $date) {
                                app(PayrollFreshnessService::class)->invalidate($employee->id, \Carbon\Carbon::parse($date));
                            }
                        }
                        DB::table('gateway_domain_effects')->insert([
                            'receipt_id' => $receipt->id, 'attendance_log_id' => $log->id, 'type' => 'calculation', 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                        ]);
                        if ($log->user?->whatsapp_number) {
                            DB::table('gateway_domain_effects')->insert([
                                'receipt_id' => $receipt->id, 'attendance_log_id' => $log->id, 'type' => 'notification', 'state' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                            ]);
                        }
                    }
                }
            } elseif ($receipt->kind === 'users') {
                foreach ($payload['users'] as $user) {
                    DB::table('gateway_device_users')->updateOrInsert(
                        ['device_id' => $device->id, 'pin' => $user['pin']],
                        ['name' => $user['name'] ?? null, 'card' => $user['card'] ?? null, 'receipt_id' => $receipt->id, 'created_at' => now(), 'updated_at' => now()]
                    );
                    $pin = trim((string) $user['pin']);
                    if ($pin === '') {
                        continue;
                    }
                    $employee = User::firstOrNew(['pin' => $pin]);
                    if (! $employee->exists) {
                        $employee->privilege = 0;
                        $employee->is_enabled = true;
                    }
                    $name = trim((string) ($user['name'] ?? ''));
                    if ($name !== '') {
                        $employee->name = $name;
                    } elseif (! $employee->exists && blank($employee->name)) {
                        $employee->name = "Employee {$pin}";
                    }
                    // Device imports only own PIN and display name. Payroll,
                    // login access, branches and local employee settings remain local.
                    $employee->save();
                }
            } elseif ($receipt->kind === 'biometric_photos') {
                foreach ($payload['photos'] as $index => $photo) {
                    $contents = base64_decode($photo['content'], true);
                    $image = $contents !== false ? @getimagesizefromstring($contents) : false;
                    if (! $contents || strlen($contents) > 786432 || ! is_array($image) || ($image['mime'] ?? null) !== 'image/jpeg') {
                        throw new \RuntimeException('Invalid device JPEG.');
                    }
                    $employee = User::where('pin', $photo['pin'])->lockForUpdate()->first();
                    if (! $employee) {
                        continue;
                    }
                    // Stable receipt paths make replay safe without public photo URLs.
                    $path = 'biometric-photos/'.$receipt->id.'-'.$index.'.jpg';
                    if (! \Illuminate\Support\Facades\Storage::disk('local')->put($path, $contents, ['visibility' => 'private'])) {
                        throw new \RuntimeException('Unable to save device photo.');
                    }
                    $employee->update(['ai_face_photo_path' => $path]);
                }
            } elseif ($receipt->kind === 'biometrics') {
                foreach ($payload['biometrics'] as $template) {
                    $employee = User::where('pin', trim((string) $template['pin']))->lockForUpdate()->first();
                    if (! $employee) {
                        continue;
                    }
                    $column = $template['type'] === 'face' ? 'face_templates' : 'fingerprints';
                    $templates = is_array($employee->{$column}) ? $employee->{$column} : [];
                    $templates[(string) $template['slot']] = array_filter([
                        'size' => $template['size'] ?? null,
                        'valid' => $template['valid'] ?? null,
                        'tmp' => $template['tmp'],
                        'bio_type' => $template['bio_type'] ?? null,
                        'index' => $template['index'] ?? null,
                        'major_ver' => $template['major_ver'] ?? null,
                        'minor_ver' => $template['minor_ver'] ?? null,
                        'format' => $template['format'] ?? null,
                    ], fn ($value) => $value !== null);
                    $employee->{$column} = $templates;
                    if ($template['type'] === 'face') {
                        $employee->face_enrolled = true;
                    }
                    $employee->save();
                }
            } elseif ($receipt->kind === 'device_info') {
                $device->update($payload['device_info']);
            } elseif ($receipt->kind === 'command_result') {
                $submission = GatewayCommandSubmission::findOrFail($payload['command_id']);
                if ($submission->binding_id !== $receipt->binding_id || $submission->binding_version !== $receipt->binding_version || $submission->organisation_id !== $receipt->organisation_id) {
                    throw new \RuntimeException('Command ownership mismatch.');
                }
                $command = DeviceCommand::whereKey($submission->projection_id)->where('gateway_command_id', $submission->id)->lockForUpdate()->firstOrFail();
                if (! $command->gateway_result_at || $received->gt(Carbon::parse($command->gateway_result_at))) {
                    $status = match ($payload['command_state']) {
                        'delivered', 'awaiting_data' => 'sent',
                        default => $payload['command_state'],
                    };
                    // Terminal evidence cannot be overwritten by a delayed delivery event.
                    if (! in_array($command->status, ['acknowledged', 'failed', 'expired', 'cancelled'], true)) {
                        $command->update(['status' => $status, 'gateway_result_at' => $received,
                            'sent_at' => $command->sent_at ?? $received,
                            'acknowledged_at' => $status === 'acknowledged' ? $received : null,
                            'response' => ($payload['error_code'] ?? null) === 'read_timeout' ? 'Read timed out: no matching device data returned within 60 seconds.' : ($payload['command_state'] === 'awaiting_data' ? 'Waiting for device data.' : (isset($payload['return_code']) ? ($payload['return_code'] === -1004 ? 'This device does not support the requested command table (return -1004).' : 'Device return code: '.$payload['return_code']) : null))]);
                    }
                }
            }
        }, 3);
    }
}
