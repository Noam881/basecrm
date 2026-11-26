<?php

namespace Awove\Vault\Console;

use App\Models\User;
use Awove\Vault\Enrollment;
use Illuminate\Console\Command;

class Approve extends Command
{
    protected $signature = 'vault:approve {email} {--operator=} {--confirm-installation=}';

    protected $description = 'Approve a vault enrollment using a verified enrolled administrator and protected operator console';

    public function handle(): int
    {
        try {
            $operator = Operator::authenticate($this);
            $target = User::where('email', $this->argument('email'))->where('active', true)->firstOrFail();
            Operator::invitation($this, app(Enrollment::class)->approve($operator, $target));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Vault enrollment approval refused. Check the explicit installation, administrator factors, account state and existing enrollment.');

            return self::FAILURE;
        }
    }
}
