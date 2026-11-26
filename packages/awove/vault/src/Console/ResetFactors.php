<?php

namespace Awove\Vault\Console;

use App\Models\User;
use Awove\Vault\Enrollment;
use Illuminate\Console\Command;

class ResetFactors extends Command
{
    protected $signature = 'vault:reset-factors {email} {--operator=} {--confirm-installation=}';

    protected $description = 'Invalidate vault authentication and approve fresh enrollment using another verified administrator';

    public function handle(): int
    {
        try {
            $operator = Operator::authenticate($this);
            $target = User::where('email', $this->argument('email'))->where('active', true)->firstOrFail();
            Operator::invitation($this, app(Enrollment::class)->approve($operator, $target, true));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Vault factor reset refused. Use a verified administrator, explicit installation and a configured security notice transport.');

            return self::FAILURE;
        }
    }
}
