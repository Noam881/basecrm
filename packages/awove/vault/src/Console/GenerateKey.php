<?php

namespace Awove\Vault\Console;

use Awove\Vault\KeyPaths;
use Illuminate\Console\Command;

class GenerateKey extends Command
{
    protected $signature = 'vault:keyring {path : Absolute path outside the project/public directory} {--key-version=v1} {--add : Add and activate a new version while retaining existing keys}';

    protected $description = 'Write dedicated random Vault keys directly into an owner-only external keyring file';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        $version = (string) $this->option('key-version');
        $directory = realpath(dirname($path));
        if (! extension_loaded('sodium') || ! str_starts_with($path, '/') || ! $directory || ! is_writable($directory)
            || ! KeyPaths::external($path)
            || (fileperms($directory) & 0022) !== 0 || is_link($path) || is_link($path.'.lock') || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $version)) {
            $this->error('Choose a protected external directory and a valid new key version.');

            return self::FAILURE;
        }
        $oldMask = umask(0077);
        $temporary = null;
        $lock = null;
        try {
            $lock = fopen($path.'.lock', 'c');
            if (! is_resource($lock) || ! chmod($path.'.lock', 0600) || ! flock($lock, LOCK_EX)) {
                throw new \RuntimeException;
            }
            if ($this->option('add')) {
                if (! is_file($path) || filesize($path) > 65536 || (fileperms($path) & 0077) !== 0) {
                    throw new \RuntimeException;
                }
                $ring = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
                if (! is_array($ring['keys'] ?? null) || isset($ring['keys'][$version])) {
                    throw new \RuntimeException;
                }
            } else {
                if (file_exists($path)) {
                    throw new \RuntimeException;
                }
                $ring = ['keys' => []];
            }
            $key = sodium_crypto_aead_xchacha20poly1305_ietf_keygen();
            $ring['keys'][$version] = base64_encode($key);
            sodium_memzero($key);
            $ring['active_key'] = $version;
            $temporary = tempnam($directory, '.vault-keyring-');
            if (! $temporary || ! chmod($temporary, 0600) || file_put_contents($temporary, json_encode($ring, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
                throw new \RuntimeException;
            }
            if ($this->option('add')) {
                if (! rename($temporary, $path)) {
                    throw new \RuntimeException;
                }
            } else {
                // link is atomic and refuses an existing path, including a racing creation.
                if (! link($temporary, $path)) {
                    throw new \RuntimeException;
                }
                unlink($temporary);
            }
            $temporary = null;
            $this->info('Dedicated keyring written. Protect its separate backup; key values were not printed.');

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('The dedicated keyring could not be written. Existing key material was preserved.');

            return self::FAILURE;
        } finally {
            if ($temporary && is_file($temporary)) {
                unlink($temporary);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            umask($oldMask);
        }
    }
}
