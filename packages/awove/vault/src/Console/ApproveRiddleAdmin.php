<?php

namespace Awove\Vault\Console;

use App\Models\User;
use Awove\Vault\Riddle;
use Illuminate\Console\Command;

class ApproveRiddleAdmin extends Command
{
    protected $signature = 'vault:approve-riddle-admin {email} {--confirm-installation=}';

    protected $description = 'Approve an explicitly selected existing full-granted CRM administrator for email-and-riddle Vault access';

    public function handle(Riddle $riddle): int
    {
        try {
            $riddle->approve($this->option('confirm-installation'), User::where('email', $this->argument('email'))->firstOrFail());
            $this->info('Vault administrator approved. Existing action grants and strong-mode factors were preserved.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Vault approval refused. Verify installation, deployment mode, verified Administrator account and exact existing grants.');

            return self::FAILURE;
        }
    }
}
