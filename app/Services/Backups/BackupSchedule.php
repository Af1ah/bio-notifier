<?php

namespace App\Services\Backups;

use App\Models\OrganisationBackupRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;

final class BackupSchedule
{
    public static function next(?string $frequency, string $time, string $timezone, ?CarbonImmutable $after = null): ?CarbonImmutable
    {
        if ($frequency === null) {
            return null;
        }

        $local = ($after ?? CarbonImmutable::now())->setTimezone($timezone);
        $date = match ($frequency) {
            'daily' => $local->startOfDay(),
            'weekly' => $local->startOfWeek(CarbonImmutable::MONDAY),
            'monthly' => $local->startOfMonth(),
        };

        $candidate = $date->setTimeFromTimeString($time);
        if ($candidate <= $local) {
            $date = match ($frequency) {
                'daily' => $date->addDay(),
                'weekly' => $date->addWeek(),
                'monthly' => $date->addMonthNoOverflow(),
            };
            $candidate = $date->setTimeFromTimeString($time);
        }

        return $candidate->utc();
    }

    public static function lock(string $organisationId): Lock
    {
        return tenancy()->central(fn () => Cache::lock('organisation-drive-backup:'.$organisationId, 660));
    }

    public static function queue(string $organisationId, string $connectionId): OrganisationBackupRun
    {
        return OrganisationBackupRun::create([
            'organisation_id' => $organisationId,
            'connection_id' => $connectionId,
            'status' => 'queued',
            'available_at' => now(),
        ]);
    }
}
