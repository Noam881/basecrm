<?php

namespace Awove\Vault\Console;

use App\Models\User;
use Awove\Vault\Enrollment;
use Illuminate\Console\Command;

class Bootstrap extends Command
{
    protected $signature = 'vault:bootstrap {email} {--confirm-installation=}';

    protected $description = 'Approve the first vault administrator on an explicitly selected installation';

    public function handle(): int
    {
        try {
            Operator::installation($this);
            $target = User::where('email', $this->argument('email'))->where('active', true)->firstOrFail();
            Operator::invitation($this, app(Enrollment::class)->bootstrap($target));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Vault bootstrap refused. Verify installation selection, account verification, module installation and whether an administrator already exists.');

            return self::FAILURE;
        }
    }
}
