<?php

namespace App\Console\Commands;

use App\Site\SiteBuilder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Renders the public website (www.mossfield.ie) to static files. See
 * docs/WEBSITE.md for previewing and uploading the result. A bad entry in
 * the site data files throws, so the exit code is non-zero and a future
 * scheduled run will not publish a half-built site.
 */
class BuildSite extends Command
{
    protected $signature = 'site:build
                            {--out= : Write the site here instead of config(site.build_dir)}
                            {--clean : Empty the build directory first, removing stale files}';

    protected $description = 'Render the public website (www.mossfield.ie) to static HTML';

    public function handle(SiteBuilder $builder): int
    {
        $dir = $this->option('out') ?: config('site.build_dir');

        $result = $builder->build($dir, (bool) $this->option('clean'));

        $message = 'site:build wrote '.count($result->files)." files to {$result->dir}";
        $this->info($message);
        Log::channel('sync')->info($message);

        return self::SUCCESS;
    }
}
