<?php

namespace Awove\Vault\Imports;

use Awove\Core\Imports\Metadata;
use Awove\Core\Installation;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\ImportFileTransfer;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Fixed journal-owned paths; this class accepts ciphertext only from the Vault writer. */
final class TransferStorage
{
    public function boundary(): string
    {
        $root = $this->root();
        $stat = stat($root);
        abort_unless(is_array($stat), 409);

        return hash_hmac('sha256', json_encode(['vault-import-storage-v1', $root, $stat['dev'], $stat['ino']], JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    }

    public function path(string $id): string
    {
        return 'vault/'.hash('sha256', (string) Installation::manifest()['id']).'/imports/'.Metadata::uuid($id).'.enc';
    }

    public function exists(ImportFileTransfer $transfer): bool
    {
        return is_file($this->absolute($transfer));
    }

    /** Accepted native rotations change the reference, never the original reserved ownership path. */
    public function current(ImportFileTransfer $transfer, Attachment $file): bool
    {
        abort_unless($file->installation_id === Installation::manifest()['id'] && $file->id === $transfer->file_id && $file->item_id === $transfer->item_id, 409);
        $path = $file->private_path;
        if ($path !== $transfer->private_path) {
            $prefix = 'vault/'.hash('sha256', (string) Installation::manifest()['id']).'/';
            abort_unless(str_starts_with($path, $prefix) && preg_match('/^([a-f0-9-]{36})\.enc$/D', substr($path, strlen($prefix)), $parts), 409);
            Metadata::uuid($parts[1]);
        }

        return is_file($this->absolute($transfer, false, $path));
    }

    public function write(ImportFileTransfer $transfer, string $ciphertext): void
    {
        $path = $this->absolute($transfer, true);
        abort_unless(! file_exists($path) && strlen($ciphertext) <= 14681088, 409);
        $stream = @fopen($path, 'xb');
        abort_unless(is_resource($stream), 503);
        try {
            abort_unless(chmod($path, 0600) && fwrite($stream, $ciphertext) === strlen($ciphertext) && fflush($stream) && fsync($stream), 503);
        } finally {
            fclose($stream);
        }
    }

    public function cleanup(ImportFileTransfer $transfer): void
    {
        $path = $this->absolute($transfer);
        if (file_exists($path)) {
            abort_unless(is_file($path) && ! is_link($path) && unlink($path), 409);
        }
    }

    private function absolute(ImportFileTransfer $transfer, bool $create = false, ?string $reference = null): string
    {
        abort_unless($transfer->installation_id === Installation::manifest()['id'] && hash_equals($transfer->storage_boundary, $this->boundary())
            && $transfer->private_path === $this->path($transfer->id), 409);
        $root = $this->root();
        $relative = $reference ?? $transfer->private_path;
        $parent = $root;
        $parts = explode('/', dirname($relative));
        foreach ($parts as $index => $part) {
            $parent .= '/'.$part;
            abort_if(is_link($parent), 409);
            if (! file_exists($parent)) {
                if (! $create) {
                    return $root.'/'.$relative;
                }
                abort_unless(@mkdir($parent, 0700) || is_dir($parent), 503);
            }
            abort_unless(is_dir($parent) && realpath($parent) === $parent && ($index === 0 || (fileperms($parent) & 0077) === 0), 409);
        }
        $path = $root.'/'.$relative;
        abort_if(is_link($path) || file_exists($path) && ! is_file($path), 409);

        return $path;
    }

    private function root(): string
    {
        clearstatcache(true);
        $disk = Storage::disk('local');
        $lexical = rtrim($disk->path(''), '/');
        $root = realpath($lexical);
        abort_unless(config('filesystems.disks.local.driver') === 'local' && $disk->getAdapter() instanceof LocalFilesystemAdapter
            && is_string($root) && is_dir($root) && $root !== '/', 409);
        foreach ([public_path(), realpath(public_path()), storage_path('app/public'), realpath(storage_path('app/public'))] as $public) {
            if (is_string($public)) {
                abort_if($root === $public || str_starts_with($root, rtrim($public, '/').'/') || $lexical === $public || str_starts_with($lexical, rtrim($public, '/').'/'), 409);
            }
        }

        return $root;
    }
}
