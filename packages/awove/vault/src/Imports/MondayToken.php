<?php

namespace Awove\Vault\Imports;

use Awove\Core\Installation;
use Awove\Vault\Cipher;

final class MondayToken
{
    public function configured(): bool
    {
        try {
            $token = $this->read();
            sodium_memzero($token);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function read(): string
    {
        $data = json_decode(PrivateFile::read('vault.monday_token_file', 32768), true, 8, JSON_THROW_ON_ERROR);
        abort_unless(is_array($data) && count($data) === 4 && ($data['version'] ?? null) === 1
            && ($data['installation_id'] ?? null) === Installation::manifest()['id']
            && is_string($data['ciphertext'] ?? null) && is_string($data['key_version'] ?? null), 503);
        $token = app(Cipher::class)->decrypt($data['ciphertext'], $data['key_version'], $this->context());
        $this->valid($token);

        return $token;
    }

    public function configure(string $installation, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $confirmation): void
    {
        abort_unless($installation === Installation::manifest()['id'] && config('app.debug') === false && hash_equals($token, $confirmation), 403);
        $this->valid($token);
        $path = PrivateFile::path('vault.monday_token_file', false);
        $sealed = app(Cipher::class)->encrypt($token, $this->context());
        $bytes = json_encode(['version' => 1, 'installation_id' => $installation, ...$sealed], JSON_THROW_ON_ERROR);
        $temporary = dirname($path).'/.monday-token-'.bin2hex(random_bytes(16));
        $stream = @fopen($temporary, 'xb');
        abort_unless(is_resource($stream), 503);
        try {
            abort_unless(chmod($temporary, 0600) && fwrite($stream, $bytes) === strlen($bytes) && fflush($stream) && fsync($stream), 503);
            fclose($stream);
            $stream = null;
            abort_unless(PrivateFile::path('vault.monday_token_file', false) === $path && rename($temporary, $path), 503);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            if (is_file($temporary) && ! is_link($temporary)) {
                unlink($temporary);
            }
            sodium_memzero($token);
            sodium_memzero($confirmation);
        }
    }

    private function valid(#[\SensitiveParameter] string $token): void
    {
        abort_unless(strlen($token) >= 16 && strlen($token) <= 8192 && ! preg_match('/[\s\x00-\x1f\x7f]/', $token), 503);
    }

    private function context(): string
    {
        return 'import:monday-token:'.Installation::manifest()['id'];
    }
}
