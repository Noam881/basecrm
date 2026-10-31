<?php

namespace Awove\Core\Console;

use Awove\Core\Installation;
use Awove\Core\Modules;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class Diagnose extends Command
{
    protected $signature = 'crm:diagnose';

    protected $description = 'Print non-secret release, module and migration diagnostics';

    public function handle(): int
    {
        app(Modules::class)->validate();
        $this->line(json_encode(['installation' => Installation::manifest()['id'], 'host' => config('app.version', 'unknown'), 'core' => config('core.version'), 'contract' => config('core.contract'), 'modules' => array_map(fn ($m) => ['version' => $m->version(), 'enabled' => app(Modules::class)->enabled($m->id())], app(Modules::class)->installed()), 'migrations' => DB::table('migrations')->pluck('migration'), 'failed_jobs' => DB::table('failed_jobs')->count()], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }
}
