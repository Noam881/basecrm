<?php

namespace Awove\Vault\Console;

use Awove\Vault\Rotation;
use Illuminate\Console\Command;

class RotateKeys extends Command
{
    protected $signature = 'vault:rotate-keys {--apply : Commit the verified rotation; default is verification only}';

    protected $description = 'Verify or rotate dedicated Vault ciphertext to the active configured key version';

    public function handle(Rotation $rotation): int
    {
        try {
            $result = $rotation->run((bool) $this->option('apply'));
            $this->info(json_encode($result, JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Vault rotation failed. Existing ciphertext was retained; verify keys and the encrypted backup before retrying.');

            return self::FAILURE;
        }
    }
}
