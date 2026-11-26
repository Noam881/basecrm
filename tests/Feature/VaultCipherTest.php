<?php

use Awove\Core\Installation;
use Awove\Vault\Cipher;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->vaultCipherKey = base64_encode(random_bytes(32));
    config(['vault.key_file' => null, 'vault.active_key' => 'test-key', 'vault.keys' => ['test-key' => $this->vaultCipherKey]]);
});

it('uses independent authenticated envelopes bound to the installation record and purpose while preserving exact UTF8 bytes', function () {
    $plain = "  FICTIONAL-CRYPTO-TEST-ONLY\nמפתח לדוגמה\n\t  ";
    $cipher = app(Cipher::class);
    $first = $cipher->encrypt($plain, 'item:fictional-record-a');
    $second = $cipher->encrypt($plain, 'item:fictional-record-a');
    expect($first['ciphertext'])->not->toBe($second['ciphertext'])->not->toContain($plain)
        ->and($cipher->decrypt($first['ciphertext'], 'test-key', 'item:fictional-record-a'))->toBe($plain);
    foreach (['item:fictional-record-b', 'file:fictional-record-a:file-a:content', 'member:1:totp'] as $context) {
        expect(fn () => $cipher->decrypt($first['ciphertext'], 'test-key', $context))->toThrow(HttpException::class);
    }
    $bytes = base64_decode($first['ciphertext']);
    $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 1);
    expect(fn () => $cipher->decrypt(base64_encode($bytes), 'test-key', 'item:fictional-record-a'))->toThrow(HttpException::class)
        ->and(fn () => $cipher->decrypt('not-an-envelope', 'test-key', 'item:fictional-record-a'))->toThrow(HttpException::class);
    $manifest = tempnam(sys_get_temp_dir(), 'vault-other-cipher-');
    file_put_contents($manifest, json_encode([...Installation::manifest(), 'id' => 'different-fictional-installation']));
    try {
        config(['core.manifest' => $manifest]);
        expect(fn () => $cipher->decrypt($first['ciphertext'], 'test-key', 'item:fictional-record-a'))->toThrow(HttpException::class);
    } finally {
        unlink($manifest);
    }
});

it('fails closed for missing wrong malformed and application-cookie key material', function () {
    $cipher = app(Cipher::class);
    $envelope = $cipher->encrypt('FICTIONAL-KEY-TEST-ONLY', 'item:fictional-key-test');
    foreach ([[], ['test-key' => 'invalid'], ['test-key' => base64_encode(random_bytes(32))]] as $keys) {
        config(['vault.keys' => $keys]);
        expect(fn () => $cipher->decrypt($envelope['ciphertext'], 'test-key', 'item:fictional-key-test'))->toThrow(HttpException::class);
    }
    $appKey = config('app.key');
    $appKey = str_starts_with($appKey, 'base64:') ? substr($appKey, 7) : base64_encode($appKey);
    config(['vault.keys' => ['test-key' => $appKey]]);
    expect($cipher->configured())->toBeFalse()
        ->and(fn () => $cipher->encrypt('FICTIONAL-ONLY', 'item:test'))->toThrow(HttpException::class);
});

it('writes dedicated keys into an owner-only external keyring without revealing or overwriting them and retains versions when adding', function () {
    $directory = sys_get_temp_dir().'/vault-keyring-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $path = $directory.'/keyring.json';
    try {
        expect(Artisan::call('vault:keyring', ['path' => $path, '--key-version' => 'test-one']))->toBe(0);
        $first = json_decode(file_get_contents($path), true);
        expect(fileperms($path) & 0077)->toBe(0)->and(strlen(base64_decode($first['keys']['test-one'])))->toBe(32);
        expect(Artisan::call('vault:keyring', ['path' => $path, '--key-version' => 'replacement']))->toBe(1);
        expect(json_decode(file_get_contents($path), true))->toBe($first);
        expect(Artisan::call('vault:keyring', ['path' => $path, '--key-version' => 'test-two', '--add' => true]))->toBe(0);
        $second = json_decode(file_get_contents($path), true);
        expect($second['keys']['test-one'])->toBe($first['keys']['test-one'])->and($second['active_key'])->toBe('test-two');
        config(['vault.key_file' => $path]);
        expect(app(Cipher::class)->configured())->toBeTrue();
        chmod($path, 0644);
        clearstatcache(true, $path);
        expect(app(Cipher::class)->configured())->toBeFalse();
    } finally {
        @unlink($path);
        @unlink($path.'.lock');
        rmdir($directory);
    }
});

it('rejects project and public lexical paths even when a parent symlink resolves to a protected external key directory', function () {
    $directory = sys_get_temp_dir().'/vault-key-alias-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    $alias = public_path('vault-key-alias-'.bin2hex(random_bytes(8)));
    $keyPath = $directory.'/keyring.json';
    file_put_contents($keyPath, json_encode(['active_key' => 'test-key', 'keys' => ['test-key' => base64_encode(random_bytes(32))]]));
    chmod($keyPath, 0600);
    symlink($directory, $alias);
    try {
        config(['vault.key_file' => $keyPath]);
        expect(app(Cipher::class)->configured())->toBeTrue();
        config(['vault.key_file' => $alias.'/keyring.json']);
        expect(app(Cipher::class)->configured())->toBeFalse();
        // The keyring command must not provision a publicly reachable alias either.
        expect(Artisan::call('vault:keyring', ['path' => $alias.'/new-keyring.json', '--key-version' => 'test-new']))->toBe(1)
            ->and(is_file($directory.'/new-keyring.json'))->toBeFalse();
        config(['vault.key_file' => public_path('../public/'.basename($alias).'/keyring.json')]);
        expect(app(Cipher::class)->configured())->toBeFalse();
    } finally {
        @unlink($alias);
        @unlink($keyPath);
        @unlink($directory.'/new-keyring.json.lock');
        rmdir($directory);
    }
});
