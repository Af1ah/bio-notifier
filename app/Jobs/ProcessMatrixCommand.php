<?php

namespace App\Jobs;

use App\Models\DeviceCommand;
use App\Models\Organisation;
use App\Services\Attendance\MatrixDeviceService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMatrixCommand implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public int $commandId,
        public string $organisationId,
    ) {}

    public function handle(): void
    {
        $command = null;
        // This job can be executed synchronously from a Livewire action. Keep
        // the request's tenant context intact so Filament's subsequent refresh
        // does not fall back to the central database.
        $previousTenant = tenancy()->tenant;

        try {
            tenancy()->initialize(Organisation::findOrFail($this->organisationId));
            $command = DeviceCommand::findOrFail($this->commandId);
            $device = $command->device;

            if (! $device) {
                $command->update(['status' => 'failed', 'response' => 'Device not found for command.']);
                return;
            }

            if ($device->connection_mode === 'remote') {
                $claimed = DeviceCommand::whereKey($command->id)->where('status','pending')->where('outcome_unknown',false)
                    ->update(['status'=>'sent','sent_at'=>now()]);
                if (!$claimed) return;
            }
            $matrixService = app(MatrixDeviceService::class);
            $content = $command->command_content;

            if (str_starts_with($content, 'INFO') || str_starts_with($content, 'CHECK')) {
                $result = $matrixService->checkConnection($device);
                if ($result['success']) {
                    $device->update([
                        'status' => 'online',
                        'model' => $result['model'] ?? $device->model,
                        'last_activity_at' => now(),
                    ]);
                    $command->update(['status' => 'acknowledged', 'response' => $result['message']]);
                } else {
                    $device->update(['status' => 'offline']);
                    $command->update(['status' => 'failed', 'response' => $result['message']]);
                }

            } elseif (str_starts_with($content, 'DATA QUERY USERINFO')) {
                $result = $matrixService->getUserCount($device);
                if ($result['success']) {
                    $command->update(['status' => 'acknowledged', 'response' => $result['message']]);
                } else {
                    $command->update(['status' => 'failed', 'response' => $result['message']]);
                }

            } elseif (str_starts_with($content, 'DATA USER ')) {
                $pin = $this->commandField($content, 'PIN');
                $name = $this->commandField($content, 'Name');

                if ($pin) {
                    $result = $matrixService->setUser($device, [
                        'pin' => $pin,
                        'name' => $name,
                        'password' => $this->commandField($content, 'Passwd'),
                        'card' => $this->commandField($content, 'Card'),
                        'user_active' => $this->commandField($content, 'Active') !== '0',
                    ]);

                    if ($result['success']) {
                        $command->update(['status' => 'acknowledged', 'response' => $result['message']]);
                    } else {
                        $command->update(['status' => 'failed', 'response' => $result['message']]);
                    }
                } else {
                    $command->update(['status' => 'failed', 'response' => 'Invalid command payload (Missing PIN)']);
                }

            } elseif (str_starts_with($content, 'DATA DEL_USER')) {
                preg_match('/PIN=([^\t]+)/', $content, $pinMatch);
                $pin = $pinMatch[1] ?? '';

                if ($pin) {
                    $result = $matrixService->deleteUser($device, $pin);
                    if ($result['success']) {
                        $command->update(['status' => 'acknowledged', 'response' => $result['message']]);
                    } else {
                        $command->update(['status' => 'failed', 'response' => $result['message']]);
                    }
                } else {
                    $command->update(['status' => 'failed', 'response' => 'Invalid command payload (Missing PIN)']);
                }

            } elseif (str_starts_with($content, 'REBOOT')) {
                $command->update(['status' => 'failed', 'response' => 'Reboot command not supported via Matrix HTTP API']);

            } elseif (str_starts_with($content, 'SET OPTIONS ServerLocalTime=')) {
                $rawTime = substr($content, strlen('SET OPTIONS ServerLocalTime='));
                $dateTime = Carbon::createFromFormat('Y-m-d H:i:s', $rawTime);
                $result = $matrixService->syncTime($device, $dateTime);

                if ($result['success']) {
                    $command->update(['status' => 'acknowledged', 'response' => $result['message']]);
                } else {
                    $command->update(['status' => 'failed', 'response' => $result['message']]);
                }

            } elseif (str_starts_with($content, 'DOOR_UNLOCK')) {
                $result = $matrixService->sendDoorCommand($device, 'unlockdoor');
                $command->update([
                    'status' => $result['success'] ? 'acknowledged' : 'failed',
                    'response' => $result['message'],
                ]);

            } elseif (str_starts_with($content, 'DOOR_LOCK')) {
                $result = $matrixService->sendDoorCommand($device, 'lockdoor');
                $command->update([
                    'status' => $result['success'] ? 'acknowledged' : 'failed',
                    'response' => $result['message'],
                ]);

            } elseif (str_starts_with($content, 'DOOR_NORMALIZE')) {
                $result = $matrixService->sendDoorCommand($device, 'normalizedoor');
                $command->update([
                    'status' => $result['success'] ? 'acknowledged' : 'failed',
                    'response' => $result['message'],
                ]);

            } elseif (str_starts_with($content, 'ENROLL_BIOMETRIC ')) {
                $json = substr($content, strlen('ENROLL_BIOMETRIC '));
                $payload = json_decode($json, true) ?: [];
                $pin = $payload['pin'] ?? null;
                $type = $payload['type'] ?? 'face';
                $extra = $payload['extra'] ?? [];

                if (! $pin) {
                    $command->update(['status' => 'failed', 'response' => 'Missing PIN for biometric enrollment.']);
                } else {
                    $user = \App\Models\User::where('pin', (string) $pin)->first();
                    if ($user) {
                        $extra['name'] = $user->name;
                        $extra['user_id'] = $user->id;
                    }

                    $result = $matrixService->enrollBiometric($device, (string) $pin, $type, $extra);
                    $command->update([
                        'status' => $result['success'] ? 'acknowledged' : 'failed',
                        'response' => $result['message'],
                    ]);
                }

            } elseif (str_starts_with($content, 'ENABLE_ENROLLMENT')) {
                $result = $matrixService->enableDeviceEnrollment($device);
                $command->update([
                    'status' => $result['success'] ? 'acknowledged' : 'failed',
                    'response' => $result['message'],
                ]);

            } elseif (str_starts_with($content, 'CLEAR LOG')) {
                $command->update(['status' => 'failed', 'response' => 'Clear Log command not supported via Matrix HTTP API']);

            } else {
                $command->update(['status' => 'failed', 'response' => 'Unsupported Matrix command; no device operation was performed.']);
            }

        } catch (\Exception $e) {
            Log::error('Matrix API Error: '.$e->getMessage());
            $uncertain = isset($claimed, $device) && $claimed && $device->connection_mode === 'remote'
                && !preg_match('/^(INFO|CHECK|DATA QUERY)/', $command->command_content);
            $command?->update(['status' => 'failed', 'outcome_unknown' => $uncertain,
                'response' => $uncertain ? 'Outcome unknown after an execution error. Reconcile device state before retrying.' : 'Matrix API Error: '.$e->getMessage()]);
        } finally {
            if ($command && isset($device) && $device?->connection_mode === 'remote' && isset($result)
                && !preg_match('/^(INFO|CHECK|DATA QUERY)/', $command->command_content)
                && !empty($result['outcome_unknown'])) {
                $command->update(['status'=>'failed','outcome_unknown'=>true,
                    'response'=>'Outcome unknown after a transport interruption. Reconcile device state before retrying.']);
            }
            if ($command && isset($device) && $device?->connection_mode === 'remote' && $command->fresh()?->status === 'sent') {
                $command->update(['status'=>'failed','outcome_unknown'=>true,'response'=>'Execution ended without a confirmed device result. Reconcile before retrying.']);
            }
            if ($previousTenant) {
                tenancy()->initialize($previousTenant);
            } else {
                tenancy()->end();
            }
        }
    }

    private function commandField(string $content, string $name): ?string
    {
        preg_match('/(?:^|\\s)'.preg_quote($name, '/').'=([^\\t]*)/', $content, $match);

        return isset($match[1]) && $match[1] !== '' ? trim($match[1]) : null;
    }
}
