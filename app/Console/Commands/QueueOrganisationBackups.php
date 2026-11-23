<?php

namespace App\Console\Commands;

use App\Jobs\RunOrganisationBackups;
use App\Models\OrganisationBackupRun;
use App\Models\OrganisationBackupSetting;
use Illuminate\Console\Command;

class QueueOrganisationBackups extends Command
{
    protected $signature = 'organisations:queue-backups';

    protected $description = 'Queue due organisation Google Drive backups';

    public function handle(): int
    {
        $runs = OrganisationBackupRun::query()->where('available_at', '<=', now())->select('organisation_id');

        OrganisationBackupSetting::query()
            ->where(fn ($query) => $query->where('next_run_at', '<=', now())->orWhereIn('organisation_id', $runs))
            ->select('organisation_id')
            ->lazyById(column: 'organisation_id')
            ->each(fn ($settings) => RunOrganisationBackups::dispatch($settings->organisation_id)
                ->onConnection('backups')->onQueue('backup-uploads'));

        return self::SUCCESS;
    }
}
