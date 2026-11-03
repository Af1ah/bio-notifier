<?php

namespace App\Services\Support;

use App\Casts\ProtectedArray;
use App\Filament\Tenant\Resources\AttendanceCalculationRuns\AttendanceCalculationRunResource;
use App\Filament\Tenant\Resources\DeviceCommandResource;
use App\Models\DeviceCommand;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class QueuedActionNotifications
{
    public function send(Model $record): void
    {
        if ($record->requested_by_user_id) {
            DB::transaction(function () use ($record) {
                $record = $record->newQuery()->lockForUpdate()->find($record->id);
                $recipient = User::loginAccounts()->find($record?->requested_by_user_id);
                if (! $recipient || ($record instanceof DeviceCommand && ! $recipient->canManageDevices())) {
                    return;
                }
                $outcome = $record instanceof DeviceCommand ? $this->commandOutcome($record) : $this->calculationOutcome($record);
                if (! $outcome) {
                    return;
                }
                [$severity, $title, $body] = $outcome;
                $key = hash('sha256', json_encode($outcome));
                if ($record->notification_outcome === $key) {
                    return;
                }
                $resource = $record instanceof DeviceCommand ? DeviceCommandResource::class : AttendanceCalculationRunResource::class;
                $parameters = ['tenant' => tenant('shortname') ?? tenant('id')];
                if ($record instanceof DeviceCommand) {
                    $parameters['record'] = $record->id;
                }
                $notification = Notification::make()->title($title)->body($body)->status($severity)
                    ->actions([Action::make('viewResult')->label('View result')->url($resource::getUrl($record instanceof DeviceCommand ? 'view' : 'index', $parameters, panel: 'tenant'))->markAsRead()]);
                // Native Filament database payload, written now in the owning tenant transaction.
                // Queueing this notification again could restore an overlapping user ID in another tenant.
                $recipient->notifyNow($notification->toDatabase());
                $record->forceFill(['notification_outcome' => $key])->saveQuietly();
            });
        }
        if ($record instanceof DeviceCommand && $record->command_type === 'query_users' && $record->gateway_command_id) {
            $outbox = DB::table('gateway_command_outbox')->where('id', $record->gateway_command_id)->first();
            $parentId = $outbox ? (ProtectedArray::decode($outbox->parameters)['__depends_on'] ?? null) : null;
            if ($parentId) {
                $parent = DeviceCommand::where('gateway_command_id', $parentId)->where('device_id', $record->device_id)->first();
                if ($parent && $parent->access_readback_id === $record->gateway_command_id) {
                    $this->send($parent);
                }
            }
        }
    }

    private function commandOutcome(DeviceCommand $command): ?array
    {
        $label = e($command->actionLabel());
        $device = e($command->device?->name ?: 'Organisation');
        if (in_array($command->status, ['failed', 'expired', 'cancelled', 'outcome_unknown'], true)) {
            $state = match ($command->status) {
                'failed' => 'failed', 'expired' => 'expired', 'cancelled' => 'cancelled', default => 'needs review',
            };

            return [$command->status === 'failed' ? 'danger' : 'warning', "$label $state", "$device · Open the result for details."];
        }
        if ($command->status !== 'acknowledged') {
            return null;
        }
        if (in_array($command->command_type, ['set_user_access_group', 'set_user_validity'], true)) {
            $state = $command->syncStatusLabel();

            return [$state === 'Synced' ? 'success' : 'warning', "$label · $state", "$device · ".($state === 'Synced' ? 'Returned settings match the request.' : 'Open the result for verification details.')];
        }
        $read = $command->gateway_command_id && (str_starts_with($command->command_type, 'query_') || $command->command_type === 'info');
        if ($read && ! $command->data_applied) {
            return null;
        }
        if ($read) {
            return ['success', "$label · Data saved", "$device · Returned device data was saved."];
        }

        if ($command->command_type === 'sync_users' && preg_match('/; ([0-9]+) skipped/', (string) $command->response, $matches) && (int) $matches[1] > 0) {
            return ['warning', 'Employee sync completed with skips', "$device · Some employees were skipped. Open the result for details."];
        }

        return $command->gateway_command_id
            ? ['success', "$label · Device confirmed", "$device · The device confirmed the command."]
            : ['success', "$label completed", "$device · eBioServer reported success."];
    }

    private function calculationOutcome(Model $run): ?array
    {
        $dates = $run->from_date->format('d M Y').' – '.$run->to_date->format('d M Y');

        return match ($run->state) {
            'completed' => ['success', 'Attendance calculation completed', "$dates · {$run->completed} employee-days calculated."],
            'completed_with_skips' => ['warning', 'Attendance calculation completed with skips', "$dates · {$run->completed} calculated, {$run->skipped} skipped."],
            'failed', 'needs_reconciliation' => ['danger', 'Attendance calculation needs attention', "$dates · Open the result for details."],
            default => null,
        };
    }
}
