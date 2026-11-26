<?php

namespace Awove\Vault\Console;

use App\Models\User;
use Awove\Vault\DevQuestion;
use Illuminate\Console\Command;

class SetDevelopmentQuestion extends Command
{
    protected $signature = 'vault:dev-question {email} {--confirm-installation=}';

    protected $description = 'Set a hashed per-user development answer only in an explicitly fictional isolated local vault';

    public function handle(): int
    {
        try {
            Operator::installation($this);
            abort_unless(DevQuestion::enabled(), 403);
            $target = User::where('email', $this->argument('email'))->where('active', true)->firstOrFail();
            $answer = $this->secret('Development-only answer (fictional data only)');
            $confirmation = $this->secret('Confirm development-only answer');
            abort_unless(is_string($answer) && is_string($confirmation) && hash_equals($answer, $confirmation), 422);
            DevQuestion::set($target, $answer);
            $this->info('Development answer stored as a hash. Existing unlocks and challenges were cancelled.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Development vault authentication refused. It is unavailable in production, staging and non-fictional installations.');

            return self::FAILURE;
        }
    }
}
