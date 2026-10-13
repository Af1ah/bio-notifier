<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PruneUserImports extends Command
{
    protected $signature = 'users:prune-imports';

    protected $description = 'Remove abandoned employee CSV uploads older than 24 hours';

    public function handle(): int
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path('imports/users'));
        $deleted = 0;
        if ($root) {
            foreach ($disk->allFiles('imports/users') as $file) {
                $path = realpath($disk->path($file));
                if (preg_match('#^imports/users/[a-f0-9]{64}/[^/]+$#D', $file) && $path && str_starts_with($path, $root.DIRECTORY_SEPARATOR) && $disk->lastModified($file) < now()->subDay()->getTimestamp()) {
                    $deleted += (int) $disk->delete($file);
                }
            }
        }
        $this->info("Removed {$deleted} expired employee upload(s).");

        return self::SUCCESS;
    }
}
