<?php

namespace Awove\Vault\Console;

use Awove\Vault\Readiness;
use Illuminate\Console\Command;

class Preflight extends Command
{
    protected $signature = 'vault:preflight {--confirm-installation=} {--minimum-admins=1 : Required number of enrolled full-action administrators for this activation profile}';

    protected $description = 'Report read-only boolean configuration checks without certifying delivery, backup custody or security';

    public function handle(Readiness $readiness): int
    {
        $minimum = filter_var($this->option('minimum-admins'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000]]);
        $report = $readiness->report($this->option('confirm-installation'), $minimum === false ? 0 : $minimum);
        $this->line(json_encode($report, JSON_THROW_ON_ERROR));

        return $report['automated_checks_passed'] ? self::SUCCESS : self::FAILURE;
    }
}
