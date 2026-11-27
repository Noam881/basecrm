<?php

namespace Awove\Vault\Imports;

use Awove\Vault\KeyPaths;

/** Deployment-owned private configuration, never request-selected paths. */
final class PrivateFile
{
    public static function path(string $configuration, bool $exists = true): string
    {
        clearstatcache(true);
        $path = config($configuration);
        abort_unless(is_string($path) && KeyPaths::external($path) && ! is_link($path), 503);
        $parent = dirname($path);
        abort_unless(realpath($parent) === $parent && is_dir($parent) && (fileperms($parent) & 0077) === 0, 503);
        $walk = $parent;
        while ($walk !== dirname($walk)) {
            abort_if(is_link($walk), 503);
            $walk = dirname($walk);
        }
        if ($exists || file_exists($path)) {
            abort_unless(is_file($path) && (fileperms($path) & 0077) === 0, 503);
        }

        return $path;
    }

    public static function read(string $configuration, int $maximum): string
    {
        $path = self::path($configuration);
        $size = filesize($path);
        abort_unless(is_int($size) && $size > 0 && $size <= $maximum, 503);
        $bytes = file_get_contents($path, false, null, 0, $maximum + 1);
        abort_unless(is_string($bytes) && strlen($bytes) === $size && strlen($bytes) <= $maximum, 503);

        return $bytes;
    }
}
