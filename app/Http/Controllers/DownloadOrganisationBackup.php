<?php

namespace App\Http\Controllers;

use App\Filament\Tenant\Pages\Backups;
use App\Services\Tenancy\TenantSqlBackup;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

final class DownloadOrganisationBackup
{
    public function __invoke(TenantSqlBackup $backup)
    {
        abort_unless(Backups::canAccess(), 403);

        try {
            $file = Cache::lock('organisation-sql-backup', 100)->get(fn () => $backup->create());
        } catch (RuntimeException) {
            $file = false;
        }

        if ($file === false) {
            return redirect(Backups::getUrl())->withErrors(['backup' => __('organisation-settings.backup_failed')]);
        }

        $filename = Str::slug(tenant('shortname')).'-'.now()->format('Ymd-His').'.sql';

        return response()->download($file, $filename, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/sql',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend();
    }
}
