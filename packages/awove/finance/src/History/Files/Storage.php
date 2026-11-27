<?php

namespace Awove\Finance\History\Files;

use Awove\Core\Imports\Metadata;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage as Disks;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Finance-owned local paths. Mirrors the existing Core journal policy without widening its types. */
final class Storage
{
    public function boundary(): string
    {
        $root = $this->root();
        $stat = stat($root);
        abort_unless(is_array($stat), 409, 'Finance file storage changed.');

        return hash_hmac('sha256', "finance-history-file-storage:v1\0".json_encode([$root, $stat['dev'], $stat['ino']], JSON_THROW_ON_ERROR), app('encrypter')->getKey());
    }

    public function path(string $id, string $extension): string
    {
        Metadata::uuid($id);
        abort_unless(preg_match('/^[a-z0-9]{1,12}$/D', $extension), 422, 'Invalid Finance file extension.');

        return 'finance/history/attachments/'.$id.'/'.$id.'.'.$extension;
    }

    public function inspect(Attachment $row): array
    {
        $path = $this->absolute($row);
        if (! file_exists($path)) {
            return ['exists' => false, 'bytes_match' => null];
        }
        abort_unless(is_file($path) && ! is_link($path), 409, 'Finance file needs reconciliation.');
        $hash = hash_file('sha256', $path);

        return ['exists' => true, 'bytes_match' => filesize($path) === $row->size_bytes && is_string($hash) && hash_equals($row->sha256, $hash)];
    }

    public function copyExclusive(Attachment $row, UploadedFile $file): void
    {
        $path = $this->absolute($row, true);
        abort_unless(! file_exists($path) && ! is_link($path), 409, 'Finance file needs reconciliation.');
        $input = @fopen($file->getRealPath(), 'rb');
        $output = @fopen($path, 'xb');
        if (! is_resource($input) || ! is_resource($output)) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            throw new \RuntimeException('The reserved Finance file could not be stored.');
        }
        try {
            if (! chmod($path, 0600) || stream_copy_to_stream($input, $output, $row->size_bytes + 1) !== $row->size_bytes
                || ! fflush($output) || ! fsync($output)) {
                throw new \RuntimeException('The reserved Finance file could not be stored.');
            }
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** Return the actual verified descriptor, not a subsequently reopened unverified path. */
    public function openVerified(Attachment $row)
    {
        $path = $this->absolute($row);
        abort_unless(is_file($path) && ! is_link($path), 404, 'The financial file is unavailable.');
        $stream = @fopen($path, 'rb');
        abort_unless(is_resource($stream), 404, 'The financial file is unavailable.');
        try {
            $this->verifyStream($row, $stream);

            return $stream;
        } catch (\Throwable $error) {
            fclose($stream);
            throw $error;
        }
    }

    /** @param resource $stream */
    public function verifyStream(Attachment $row, $stream): void
    {
        abort_unless(is_resource($stream) && rewind($stream), 409, 'Finance file bytes need reconciliation.');
        $hash = hash_init('sha256');
        $size = hash_update_stream($hash, $stream, $row->size_bytes + 1);
        abort_unless($size === $row->size_bytes && hash_equals($row->sha256, hash_final($hash)) && rewind($stream), 409, 'Finance file bytes need reconciliation.');
    }

    /** Only the coordinator may remove its own uncommitted, unreferenced journal path. */
    public function cleanup(Attachment $row): void
    {
        $path = $this->absolute($row);
        if (! file_exists($path)) {
            return;
        }
        abort_unless(is_file($path) && ! is_link($path), 409, 'Finance file needs reconciliation.');
        if (! unlink($path)) {
            throw new \RuntimeException('The uncommitted Finance file could not be removed.');
        }
    }

    private function root(): string
    {
        clearstatcache(true);
        $disk = Disks::disk('local');
        abort_unless(config('filesystems.disks.local.driver') === 'local' && $disk->getAdapter() instanceof LocalFilesystemAdapter, 409, 'Private local Finance file storage is required.');
        $lexical = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        $root = realpath($lexical);
        abort_unless(is_string($root) && is_dir($root) && $root !== DIRECTORY_SEPARATOR, 409, 'Finance file storage changed.');
        foreach ([public_path(), storage_path('app/public')] as $public) {
            foreach ([$public, realpath($public) ?: $public] as $prefix) {
                $prefix = rtrim($prefix, DIRECTORY_SEPARATOR);
                abort_if($lexical === $prefix || str_starts_with($lexical, $prefix.DIRECTORY_SEPARATOR)
                    || $root === $prefix || str_starts_with($root, $prefix.DIRECTORY_SEPARATOR), 409, 'Private Finance file storage is required.');
            }
        }

        return $root;
    }

    private function absolute(Attachment $row, bool $create = false): string
    {
        abort_unless(hash_equals($row->storage_boundary, $this->boundary()), 409, 'Finance file storage changed.');
        $extension = strtolower(pathinfo($row->original_name, PATHINFO_EXTENSION));
        abort_unless($row->private_path === $this->path($row->id, $extension), 409, 'Finance file path needs reconciliation.');
        $root = $this->root();
        $segments = explode('/', $row->private_path);
        array_pop($segments);
        $parent = $root;
        foreach ($segments as $index => $segment) {
            $parent .= DIRECTORY_SEPARATOR.$segment;
            abort_if(is_link($parent), 409, 'Finance file path needs reconciliation.');
            if (! file_exists($parent)) {
                if (! $create) {
                    return $root.DIRECTORY_SEPARATOR.$row->private_path;
                }
                abort_unless(@mkdir($parent, 0700) || is_dir($parent), 409, 'Private Finance file storage is unavailable.');
            }
            abort_unless(is_dir($parent) && realpath($parent) === $parent && ($index === 0 || (fileperms($parent) & 0077) === 0), 409, 'Finance file path needs reconciliation.');
        }
        $path = $root.DIRECTORY_SEPARATOR.$row->private_path;
        abort_if(is_link($path), 409, 'Finance file path needs reconciliation.');

        return $path;
    }
}
