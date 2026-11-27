<?php

namespace Awove\Vault\Console;

use Awove\Core\Installation;
use Awove\Vault\Imports\MondayToken;
use Illuminate\Console\Command;

class ConfigureMondayImport extends Command
{
    protected $signature = 'vault:configure-monday-import {--confirm-installation=}';

    protected $description = 'Configure a private encrypted Monday import token using hidden interactive input; never fetch source data';

    public function handle(MondayToken $tokens): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('A protected interactive console is required.');

            return self::FAILURE;
        }
        $token = $confirmation = null;
        try {
            abort_unless($this->option('confirm-installation') === Installation::manifest()['id'] && config('app.debug') === false, 403);
            $token = $this->secret('Monday read-only token (entered privately on this server)', false);
            $confirmation = $this->secret('Confirm token', false);
            abort_unless(is_string($token) && is_string($confirmation), 422);
            $tokens->configure((string) $this->option('confirm-installation'), $token, $confirmation);
            $this->info('Private import token configured. No source records were read and Vault remains subject to its normal unlock.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Import token configuration refused. Check the installation, protected private path and input confirmation.');

            return self::FAILURE;
        } finally {
            if (is_string($token)) {
                sodium_memzero($token);
            }
            if (is_string($confirmation)) {
                sodium_memzero($confirmation);
            }
        }
    }
}
