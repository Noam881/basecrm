<?php

namespace Awove\Vault;

/** Keep key files outside the project/public tree even through lexical symlink aliases. */
final class KeyPaths
{
    private static function normalize(string $path): ?string
    {
        if (! str_starts_with($path, '/') || str_contains($path, "\0")) {
            return null;
        }
        $parts = [];
        foreach (explode('/', $path) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($parts);
            } else {
                $parts[] = $part;
            }
        }

        return '/'.implode('/', $parts);
    }

    private static function inside(string $path, string $directory): bool
    {
        return $path === $directory || str_starts_with($path, rtrim($directory, '/').'/');
    }

    public static function external(string $path): bool
    {
        $lexical = self::normalize($path);
        $directory = realpath(dirname($path));
        if ($lexical === null || ! $directory) {
            return false;
        }
        $resolved = realpath($path) ?: $directory.'/'.basename($path);
        foreach ([base_path(), public_path(), realpath(base_path()), realpath(public_path())] as $protected) {
            if (! is_string($protected) || $protected === '') {
                continue;
            }
            $protected = self::normalize($protected);
            if ($protected !== null && (self::inside($lexical, $protected) || self::inside($resolved, $protected))) {
                return false;
            }
        }

        return true;
    }
}
