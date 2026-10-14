<?php

namespace App\Services\Leave;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LeaveSettingsService
{
    public static function timezone(): string
    {
        return DB::table('tenant_leave_settings')->where('id', 1)->value('timezone') ?? config('attendance.timezone', 'Asia/Kolkata');
    }

    public static function expiryWarningDays(): int
    {
        return (int) (DB::table('tenant_leave_settings')->where('id', 1)->value('expiry_warning_days') ?? 30);
    }

    public function save(array $data, User $actor): void
    {
        abort_unless((int) $actor->privilege === 14, 403);
        $v = Validator::make($data, ['timezone' => 'required|timezone', 'expiry_warning_days' => 'required|integer|min:0|max:365'])->validate();
        DB::table('tenant_leave_settings')->updateOrInsert(['id' => 1], $v + ['updated_by_user_id' => $actor->id, 'updated_at' => now()]);
    }
}
