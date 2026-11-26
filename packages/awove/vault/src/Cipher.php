<?php

namespace Awove\Vault;

use Awove\Core\Installation;
use SensitiveParameter;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Authenticated envelopes bind each value to its installation, purpose and record. */
final class Cipher
{
    public function configured(): bool
    {
        try {
            $this->key($this->activeVersion());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function activeVersion(): string
    {
        $version = $this->ring()['active_key'] ?? null;
        if (! is_string($version) || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D', $version)) {
            $this->unavailable();
        }

        return $version;
    }

    /** @return array{ciphertext:string,key_version:string} */
    public function encrypt(#[SensitiveParameter] string $plaintext, string $context): array
    {
        $version = $this->activeVersion();
        $key = $this->key($version);
        try {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $encrypted = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt($plaintext, $this->aad($version, $context), $nonce, $key);

            return ['ciphertext' => base64_encode($nonce.$encrypted), 'key_version' => $version];
        } finally {
            sodium_memzero($key);
        }
    }

    public function decrypt(#[SensitiveParameter] string $ciphertext, string $keyVersion, string $context): string
    {
        $key = $this->key($keyVersion);
        try {
            $bytes = base64_decode($ciphertext, true);
            if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES) {
                $this->unavailable();
            }
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($bytes, 24), $this->aad($keyVersion, $context), substr($bytes, 0, 24), $key);
            if ($plaintext === false) {
                $this->unavailable();
            }

            return $plaintext;
        } catch (\SodiumException) {
            $this->unavailable();
        } finally {
            sodium_memzero($key);
        }
    }

    private function aad(string $version, string $context): string
    {
        return json_encode(['vault-envelope-v1', (string) Installation::manifest()['id'], $version, $context], JSON_THROW_ON_ERROR);
    }

    private function key(string $version): string
    {
        if (! extension_loaded('sodium')) {
            $this->unavailable();
        }
        $encoded = $this->ring()['keys'][$version] ?? null;
        $key = is_string($encoded) ? base64_decode($encoded, true) : false;
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            $this->unavailable();
        }
        // Dedicated material must never be the application's cookie/session key.
        $appKey = (string) config('app.key');
        $appKey = str_starts_with($appKey, 'base64:') ? base64_decode(substr($appKey, 7), true) : $appKey;
        if (is_string($appKey) && hash_equals($appKey, $key)) {
            $this->unavailable();
        }

        return $key;
    }

    private function ring(): array
    {
        $file = config('vault.key_file');
        if ($file) {
            if (! is_string($file) || ! is_file($file) || is_link($file) || ! is_readable($file) || filesize($file) > 65536) {
                $this->unavailable();
            }
            // Runtime keys remain outside public storage; require owner-only file permissions.
            if (! KeyPaths::external($file) || (fileperms($file) & 0077) !== 0) {
                $this->unavailable();
            }
            try {
                $ring = json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
            } catch (\Throwable) {
                $this->unavailable();
            }
            if (! is_array($ring) || ! is_array($ring['keys'] ?? null)) {
                $this->unavailable();
            }

            return $ring;
        }

        return ['active_key' => config('vault.active_key'), 'keys' => config('vault.keys', [])];
    }

    private function unavailable(): never
    {
        throw new HttpException(503, (string) __('Vault encryption is unavailable. Contact the installation operator.'));
    }
}
