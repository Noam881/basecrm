<?php

namespace Awove\Vault\Console;

use Awove\Vault\RestoreAuthentication;
use Illuminate\Console\Command;

class InvalidateRestoredAuth extends Command
{
    protected $signature = 'vault:invalidate-restored-auth {--confirm-installation=} {--confirm-restore : Confirm an isolated dedicated-database restore in maintenance mode; all CRM logins must restart}';

    protected $description = 'Invalidate restored temporary authentication without deleting active Vault factors or customer data';

    public function handle(RestoreAuthentication $restore): int
    {
        try {
            $restore->invalidate($this->option('confirm-installation'), (bool) $this->option('confirm-restore'));
            $this->line('{"authentication_invalidated":true,"active_factors_preserved":true,"authority_reconciliation_required":true}');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->line('{"authentication_invalidated":false,"active_factors_preserved":true,"authority_reconciliation_required":true}');

            return self::FAILURE;
        }
    }
}
