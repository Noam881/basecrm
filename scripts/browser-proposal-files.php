<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/** Exact private-file handling for guarded local browser-fixture recovery only. */
final class BrowserProposalFiles
{
    private const MAX_FILES = 20;

    private const MAX_BYTES = 20 * 1024 * 1024;

    public static function pathMatches(string $path, int $leadId): bool
    {
        return $leadId > 0 && preg_match('/^proposals\/'.preg_quote((string) $leadId, '/').'\/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(pdf|doc|docx)$/D', $path) === 1;
    }

    /** @return array<string,array{content:string,sha256:string,size_bytes:int}> */
    public static function snapshot(array $rows): array
    {
        self::validateRows($rows);
        $files = [];
        $disk = self::disk();
        foreach ($rows as $row) {
            $path = $row['private_path'];
            self::assertContainedPath($disk, $path);
            if (! $disk->exists($path)) {
                throw new RuntimeException('A selected proposal file is missing; fixture cleanup was refused.');
            }
            $content = $disk->get($path);
            self::verify($content, $row);
            $files[$path] = ['content' => base64_encode($content), 'sha256' => $row['sha256'], 'size_bytes' => (int) $row['size_bytes']];
        }

        return $files;
    }

    /** Validate every path and byte before touching any local file. */
    public static function validate(array $rows, array $files): void
    {
        self::validateRows($rows);
        $paths = array_column($rows, 'private_path');
        if (count($files) !== count($paths) || array_diff($paths, array_keys($files)) || array_diff(array_keys($files), $paths)) {
            throw new RuntimeException('Proposal recovery files do not match the selected rows.');
        }
        foreach ($rows as $row) {
            $file = $files[$row['private_path']];
            if (! is_array($file) || ($file['sha256'] ?? '') !== $row['sha256'] || ($file['size_bytes'] ?? null) !== (int) $row['size_bytes'] || ! is_string($file['content'] ?? null) || strlen($file['content']) > 14 * 1024 * 1024) {
                throw new RuntimeException('A proposal recovery file has invalid metadata.');
            }
            $bytes = base64_decode($file['content'], true);
            if ($bytes === false) {
                throw new RuntimeException('A proposal recovery file is not valid base64.');
            }
            self::verify($bytes, $row);
        }
    }

    /** Restore missing files only; preserve matching existing files without overwriting them. */
    public static function restore(array $rows, array $files): array
    {
        self::validate($rows, $files);
        $disk = self::disk();
        foreach ($rows as $row) {
            self::assertContainedPath($disk, $row['private_path']);
            if ($disk->exists($row['private_path'])) {
                self::verify($disk->get($row['private_path']), $row);
            }
        }
        $created = [];
        try {
            foreach ($rows as $row) {
                $path = $row['private_path'];
                self::assertContainedPath($disk, $path);
                if ($disk->exists($path)) {
                    continue;
                }
                if (! $disk->put($path, base64_decode($files[$path]['content'], true), ['visibility' => 'private'])) {
                    throw new RuntimeException('A private proposal file could not be restored.');
                }
                $created[] = $path;
                self::assertContainedPath($disk, $path);
                self::verify($disk->get($path), $row);
            }
        } catch (Throwable $error) {
            self::discardCreated($created, $rows);
            throw $error;
        }

        return $created;
    }

    /** Only discard files created by a failed row restore, and only if their bytes still match. */
    public static function discardCreated(array $created, array $rows): void
    {
        foreach ($rows as $row) {
            if (in_array($row['private_path'], $created, true)) {
                try {
                    self::remove([$row]);
                } catch (Throwable $error) {
                    report($error);
                }
            }
        }
    }

    /** Called after row cleanup commits; the caller must reject any remaining database reference. */
    public static function remove(array $rows): void
    {
        self::validateRows($rows);
        $disk = self::disk();
        foreach ($rows as $row) {
            self::assertContainedPath($disk, $row['private_path']);
            if ($disk->exists($row['private_path'])) {
                self::verify($disk->get($row['private_path']), $row);
            }
        }
        foreach ($rows as $row) {
            self::assertContainedPath($disk, $row['private_path']);
            if ($disk->exists($row['private_path']) && ! $disk->delete($row['private_path'])) {
                throw new RuntimeException('A cleaned proposal file could not be removed; its private recovery copy is retained.');
            }
        }
    }

    private static function disk(): FilesystemAdapter
    {
        $disk = Storage::disk('local');
        if (! $disk instanceof FilesystemAdapter || ! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new RuntimeException('Browser proposal recovery requires the isolated local filesystem.');
        }

        return $disk;
    }

    /** Refuse symlink ancestors, directory components and final files before adapter operations. */
    private static function assertContainedPath(FilesystemAdapter $disk, string $path): void
    {
        $root = rtrim($disk->path(''), DIRECTORY_SEPARATOR);
        if ($root === '' || ! str_starts_with($root, DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('The private proposal disk must have an absolute local root.');
        }
        $ancestor = '';
        foreach (explode(DIRECTORY_SEPARATOR, ltrim($root, DIRECTORY_SEPARATOR)) as $component) {
            $ancestor .= DIRECTORY_SEPARATOR.$component;
            clearstatcache(true, $ancestor);
            if (is_link($ancestor)) {
                throw new RuntimeException('Symlink roots or ancestors are not permitted for private proposal recovery.');
            }
        }
        $realRoot = realpath($root);
        if ($realRoot === false || ! is_dir($realRoot)) {
            throw new RuntimeException('The private proposal recovery root is unavailable.');
        }
        $candidate = $root;
        $parts = explode('/', $path);
        foreach ($parts as $index => $component) {
            $candidate .= DIRECTORY_SEPARATOR.$component;
            clearstatcache(true, $candidate);
            if (is_link($candidate)) {
                throw new RuntimeException('Symlink proposal paths are not permitted for private recovery.');
            }
            if (! file_exists($candidate)) {
                continue;
            }
            $real = realpath($candidate);
            if ($real === false || ! str_starts_with($real, $realRoot.DIRECTORY_SEPARATOR)
                || ($index < count($parts) - 1 && ! is_dir($candidate))
                || ($index === count($parts) - 1 && ! is_file($candidate))) {
                throw new RuntimeException('A proposal path leaves the exact private installation root.');
            }
        }
    }

    private static function validateRows(array $rows): void
    {
        $paths = [];
        $bytes = 0;
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['private_path'] ?? null) || ! self::pathMatches($row['private_path'], (int) ($row['lead_id'] ?? 0))
                || ! is_string($row['sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/D', $row['sha256']) !== 1
                || ! is_numeric($row['size_bytes'] ?? null) || (int) $row['size_bytes'] < 1 || (int) $row['size_bytes'] > 10 * 1024 * 1024
                || in_array($row['private_path'], $paths, true)) {
                throw new RuntimeException('A selected proposal has an unsafe private path or invalid recovery metadata.');
            }
            $paths[] = $row['private_path'];
            $bytes += (int) $row['size_bytes'];
        }
        if (count($rows) > self::MAX_FILES || $bytes > self::MAX_BYTES) {
            throw new RuntimeException('Proposal fixture recovery is limited to 20 files and 20 MB per cleanup.');
        }
    }

    private static function verify(string $content, array $row): void
    {
        if (strlen($content) !== (int) $row['size_bytes'] || ! hash_equals($row['sha256'], hash('sha256', $content))) {
            throw new RuntimeException('A proposal file does not match its private recovery checksum. Nothing will overwrite it.');
        }
    }
}
