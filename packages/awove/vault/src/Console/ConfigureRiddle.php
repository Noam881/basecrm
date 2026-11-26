<?php

namespace Awove\Vault\Console;

use Awove\Vault\Riddle;
use Illuminate\Console\Command;

class ConfigureRiddle extends Command
{
    protected $signature = 'vault:configure-riddle {--confirm-installation=}';

    protected $description = 'Configure the deployment-selected shared Vault riddle through a protected interactive console';

    public function handle(Riddle $riddle): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('An interactive protected console is required.');

            return self::FAILURE;
        }
        try {
            $riddle->assertOperator($this->option('confirm-installation'));
            $question = $this->ask('Riddle question (shown to approved administrators)');
            $answer = $this->secret('Shared riddle passphrase');
            $confirmation = $this->secret('Confirm shared riddle passphrase');
            abort_unless(is_string($question) && is_string($answer) && is_string($confirmation), 422);
            $riddle->configure($this->option('confirm-installation'), $question, $answer, $confirmation);
            $this->info('Shared Vault riddle configured. Existing Vault challenges and unlocks were cancelled.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Shared Vault riddle configuration refused. Verify deployment mode, installation selection and private input confirmation.');

            return self::FAILURE;
        }
    }
}
