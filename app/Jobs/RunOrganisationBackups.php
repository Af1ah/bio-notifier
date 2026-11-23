<?php

namespace App\Jobs;

use App\Models\Organisation;
use App\Models\OrganisationBackupRun;
use App\Models\OrganisationBackupSetting;
use App\Services\Backups\BackupSchedule;
use App\Services\Backups\BackupTransferException;
use App\Services\Backups\GoogleDrive;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantSqlBackup;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;
use RuntimeException;

class RunOrganisationBackups implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $timeout = 600;

    public int $uniqueFor = 900;

    public int $tries = 1;

    public function __construct(public string $organisationId) {}

    public function uniqueId(): string
    {
        return $this->organisationId;
    }

    public function handle(GoogleDrive $drive, TenantSqlBackup $backup): void
    {
        BackupSchedule::lock($this->organisationId)->get(function () use ($drive, $backup) {
            $organisation = Organisation::find($this->organisationId);
            $settings = OrganisationBackupSetting::find($this->organisationId);
            if (! $organisation || ! $settings) {
                return;
            }

            if ($settings->connection_id && $settings->refresh_token && $settings->next_run_at?->lte(now())) {
                $settings->getConnection()->transaction(function () use ($settings) {
                    BackupSchedule::queue($this->organisationId, $settings->connection_id);
                    $settings->update(['next_run_at' => BackupSchedule::next(
                        $settings->frequency,
                        $settings->backup_time,
                        $settings->timezone,
                    )]);
                });
            }

            $run = OrganisationBackupRun::where('organisation_id', $this->organisationId)
                ->where('available_at', '<=', now())->orderBy('available_at')->orderBy('id')->first();
            if (! $run) {
                return;
            }

            if ($run->attempts >= 4 || ! $settings->refresh_token || $run->connection_id !== $settings->connection_id) {
                $run->update([
                    'status' => 'failed',
                    'error' => $run->attempts >= 4 ? 'interrupted' : 'connection_changed',
                    'available_at' => null,
                ]);

                return;
            }

            $run->update([
                'status' => 'running',
                'attempts' => $run->attempts + 1,
                'available_at' => now()->addMinutes(11),
            ]);

            try {
                $accessToken = $drive->accessToken($settings->refresh_token);
                if (! $run->drive_file_id) {
                    $run->update(['drive_file_id' => $drive->generateId($accessToken)]);
                }
                if (! $run->checksum || ! $drive->verified($accessToken, $run)) {
                    $this->upload($organisation, $run, $drive, $accessToken, $backup);
                }

                $run->update(['status' => 'verified', 'verified_at' => now(), 'error' => null, 'available_at' => null]);
            } catch (BackupTransferException $exception) {
                if ($exception->reason === 'drive_authorization' && ! $exception->retryable) {
                    $settings->update(['refresh_token' => null, 'next_run_at' => null]);
                }

                $retry = $exception->retryable && $run->attempts < 4;
                $run->update([
                    'status' => $retry ? 'retrying' : 'failed',
                    'error' => $exception->reason,
                    'available_at' => $retry ? now()->addMinutes(2 ** $run->attempts) : null,
                ]);
            }
        });
    }

    private function upload(Organisation $organisation, OrganisationBackupRun $run, GoogleDrive $drive, string $accessToken, TenantSqlBackup $backup): void
    {
        try {
            $file = TenantContext::run($organisation, fn () => $backup->create());
        } catch (RuntimeException) {
            throw new BackupTransferException('dump_failed');
        }

        try {
            $checksum = md5_file($file);
            $size = filesize($file);
            if ($checksum === false || ! $size) {
                throw new BackupTransferException('dump_failed');
            }

            $run->update(['checksum' => $checksum, 'bytes' => $size]);
            $filename = 'bio-notifier-'.Str::slug($organisation->shortname).'-'.$run->created_at->utc()->format('Ymd-His').'-'.$run->id.'.sql';
            $drive->upload($accessToken, $run, $file, $filename);
            if (! $drive->verified($accessToken, $run)) {
                throw new BackupTransferException('verification_unavailable');
            }
        } finally {
            unlink($file);
        }
    }
}
