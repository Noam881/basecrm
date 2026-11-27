<?php

namespace Awove\Core\Imports;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Journal-owned local paths only. No source fetching, guessed adoption, overwrite or arbitrary cleanup path. */
final class DocumentTransferStorage
{
    public function boundary(): string
    {
        $root = $this->root();
        $stat = stat($root);
        abort_unless(is_array($stat), 409, 'Document storage boundary changed.');

        return hash_hmac('sha256', "core-import-document-storage:v1\0".json_encode([$root, $stat['dev'], $stat['ino']], JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    }

    public function path(string $id, string $extension): string
    {
        $id = Metadata::uuid($id);
        abort_unless(preg_match('/^[a-z0-9]{1,12}$/D', $extension), 422, 'Invalid document extension.');

        return 'documents/imports/'.$id.'/'.$id.'.'.$extension;
    }

    public function inspect(DocumentTransfer $transfer): array
    {
        $path = $this->absolute($transfer);
        if (! file_exists($path)) {
            return ['exists' => false, 'bytes_match' => null];
        }
        abort_unless(is_file($path) && ! is_link($path), 409, 'Document storage requires reconciliation.');
        $size = filesize($path);
        $hash = hash_file('sha256', $path);

        return ['exists' => true, 'bytes_match' => $size === $transfer->size_bytes && is_string($hash) && hash_equals($transfer->sha256, $hash)];
    }

    public function copyExclusive(DocumentTransfer $transfer, UploadedFile $file): void
    {
        $path = $this->absolute($transfer, true);
        abort_unless(! file_exists($path) && ! is_link($path), 409, 'Document transfer requires reconciliation.');
        $input = @fopen($file->getRealPath(), 'rb');
        $output = @fopen($path, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new \RuntimeException('The reserved document bytes could not be stored.');
        }
        try {
            if (! chmod($path, 0600) || stream_copy_to_stream($input, $output, $transfer->size_bytes + 1) !== $transfer->size_bytes
                || ! fflush($output) || ! fsync($output)) {
                throw new \RuntimeException('The reserved document bytes could not be stored.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** Caller must first lock the journal and prove that no committed Document owns this path. */
    public function cleanup(DocumentTransfer $transfer): void
    {
        $path = $this->absolute($transfer);
        if (! file_exists($path)) {
            return;
        }
        abort_unless(is_file($path) && ! is_link($path), 409, 'Document storage requires reconciliation.');
        if (! unlink($path)) {
            throw new \RuntimeException('The uncommitted document bytes could not be removed.');
        }
    }

    private function root(): string
    {
        clearstatcache(true);
        $disk = Storage::disk('local');
        abort_unless(config('filesystems.disks.local.driver') === 'local' && $disk->getAdapter() instanceof LocalFilesystemAdapter, 409, 'Private local document storage is required.');
        $lexical = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        $root = realpath($lexical);
        abort_unless(is_string($root) && is_dir($root) && $root !== DIRECTORY_SEPARATOR, 409, 'Document storage boundary changed.');
        foreach ([public_path(), storage_path('app/public')] as $public) {
            $resolved = realpath($public) ?: $public;
            foreach ([$public, $resolved] as $prefix) {
                $prefix = rtrim($prefix, DIRECTORY_SEPARATOR);
                abort_if($lexical === $prefix || str_starts_with($lexical, $prefix.DIRECTORY_SEPARATOR)
                    || $root === $prefix || str_starts_with($root, $prefix.DIRECTORY_SEPARATOR), 409, 'Private document storage is required.');
            }
        }

        return $root;
    }

    private function absolute(DocumentTransfer $transfer, bool $create = false): string
    {
        abort_unless(hash_equals($transfer->storage_boundary, $this->boundary()), 409, 'Document storage boundary changed.');
        $extension = strtolower(pathinfo($transfer->original_name, PATHINFO_EXTENSION));
        abort_unless($transfer->private_path === $this->path($transfer->id, $extension), 409, 'Document path requires reconciliation.');
        $root = $this->root();
        $segments = explode('/', $transfer->private_path);
        array_pop($segments);
        $parent = $root;
        foreach ($segments as $index => $segment) {
            $parent .= DIRECTORY_SEPARATOR.$segment;
            abort_if(is_link($parent), 409, 'Document path requires reconciliation.');
            if (! file_exists($parent)) {
                if (! $create) {
                    // Missing directories are a missing owned file, never a reason to inspect another location.
                    return $root.DIRECTORY_SEPARATOR.$transfer->private_path;
                }
                abort_unless(@mkdir($parent, 0700) || is_dir($parent), 409, 'Private document storage is unavailable.');
            }
            abort_unless(is_dir($parent) && realpath($parent) === $parent, 409, 'Document path requires reconciliation.');
            if ($index > 0) {
                abort_unless((fileperms($parent) & 0077) === 0, 409, 'Private document storage is unavailable.');
            }
        }
        $path = $root.DIRECTORY_SEPARATOR.$transfer->private_path;
        abort_if(is_link($path), 409, 'Document path requires reconciliation.');

        return $path;
    }
}
