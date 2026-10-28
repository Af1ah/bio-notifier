<?php

namespace App\Jobs\Gateway;

use App\Models\AttendanceDay;
use App\Models\AttendanceLog;
use App\Models\Organisation;
use App\Services\Attendance\AttendanceCalculationService;
use App\Services\Attendance\ManualAttendanceProtection;
use App\Services\Devices\Gateway\GatewayCommands;
use App\Services\Payroll\PayrollFreshnessService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ApplyDomainEffect implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(public string $organisationId, public int $effectId) {}

    public function signalId(): string
    {
        return 'effect:'.$this->organisationId.':'.$this->effectId;
    }

    public function handle(AttendanceCalculationService $calculator, PayrollFreshnessService $freshness, WhatsAppService $notifications): void
    {
        if (tenancy()->initialized) {
            tenancy()->end();
        }
        tenancy()->initialize(Organisation::findOrFail($this->organisationId));
        $token = (string) Str::uuid();
        try {
            $effect = DB::table('gateway_domain_effects')->where('id', $this->effectId)->first();
            if (! $effect || $effect->state === 'applied' || $effect->state === 'parked') {
                return;
            }
            // A crashed notification sender has an ambiguous provider outcome; do not resend automatically.
            if ($effect->type === 'notification' && $effect->state === 'processing' && $effect->leased_until < now()) {
                DB::table('gateway_domain_effects')->where('id', $effect->id)->where('lease_token', $effect->lease_token)->update(['state' => 'parked', 'error_code' => 'notification_outcome_unknown']);

                return;
            }
            $claimed = DB::table('gateway_domain_effects')->where('id', $effect->id)->where('attempts', '<', 3)
                ->where(fn ($q) => $q->where('state', 'pending')->orWhere(fn ($q) => $q->where('state', 'processing')->where('leased_until', '<', now())))
                ->update(['state' => 'processing', 'lease_token' => $token, 'leased_until' => now()->addSeconds(180), 'attempts' => DB::raw('attempts + 1')]);
            if (! $claimed) {
                return;
            }
            $log = AttendanceLog::findOrFail($effect->attendance_log_id);
            $user = $log->user;
            if ($user && $effect->type === 'calculation') {
                $days = collect([$log->punched_at->copy()->subDay()->toDateString(), $log->punched_at->toDateString()]);
                $existing = AttendanceDay::where('user_id', $user->id)->whereHas('occurrences', fn ($q) => $q->where('window_starts_at', '<=', $log->punched_at)->where('window_ends_at', '>=', $log->punched_at))->get();
                foreach ($existing as $day) {
                    if (! app(ManualAttendanceProtection::class)->applies($day)) {
                        $day->update(['stale_at' => now()]);
                    }
                    $days->push($day->work_date->toDateString());
                }
                $protectedEvidence = $existing->contains(fn ($day) => app(ManualAttendanceProtection::class)->applies($day));
                foreach ($days->unique() as $date) {
                    if ($protectedEvidence && ! AttendanceDay::where('user_id', $user->id)->whereDate('work_date', $date)->exists()) {
                        continue;
                    }
                    // The calculator invalidates payroll only when the effective result may change.
                    $calculator->calculate($user, Carbon::parse($date));
                }
            } elseif ($user && in_array($effect->type, ['biometric_finger', 'biometric_face'], true)) {
                $format = substr($effect->type, strlen('biometric_'));
                $templates = $format === 'finger' ? $user->fingerprints : $user->face_templates;
                $stored = is_array($templates) && collect($templates)->contains(fn ($template) => is_array($template) && filled($template['tmp'] ?? null));
                $request = ($user->biometric_fetch_requests ?? [])[$format] ?? null;
                $device = $log->device;
                if (! $stored && $request && $device && (int) $request['device_id'] === $device->id && $device->supportsVerificationFormat($format)) {
                    $commands = app(GatewayCommands::class);
                    if ($commands->available($device, 'query_biodata')) {
                        $commands->request($device, 'query_biodata', ['pin' => (string) $user->pin, 'type' => $format], $request['command_id']);
                    }
                }
            } elseif ($user?->whatsapp_number && $effect->type === 'notification' && config('services.whatsapp.api_key') && config('services.whatsapp.instance_name')) {
                if (! $notifications->sendMessage($user->whatsapp_number, "Hello {$user->name}, your attendance punch ({$log->status_label}) at {$log->punched_at->format('Y-m-d H:i:s')} has been recorded.")) {
                    throw new \RuntimeException('Notification outcome unknown.');
                }
            }
            DB::table('gateway_domain_effects')->where('id', $effect->id)->where('lease_token', $token)->update(['state' => 'applied', 'lease_token' => null, 'leased_until' => null]);
        } catch (\Throwable $exception) {
            if (isset($effect)) {
                DB::table('gateway_domain_effects')->where('id', $effect->id)->where('lease_token', $token)->update([
                    'state' => $effect->type === 'notification' || $effect->attempts >= 2 ? 'parked' : 'pending',
                    'lease_token' => null, 'leased_until' => null, 'error_code' => $effect->type === 'notification' ? 'notification_outcome_unknown' : (str_starts_with($effect->type, 'biometric_') ? 'biometric_fetch_failed' : 'calculation_failed'),
                ]);
            }
            throw $exception;
        } finally {
            tenancy()->end();
        }
    }
}
