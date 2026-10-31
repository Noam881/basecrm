<?php

namespace App\Support;

use Awove\Core\Installation;
use Illuminate\Support\Facades\Lang;

/** Installation language affects interface text, never authored domain values. */
class Localization
{
    public static function register(): void
    {
        foreach (glob(base_path('vendor/awove/*/resources/lang'), GLOB_ONLYDIR) ?: [] as $path) {
            Lang::addJsonPath($path);
            if (is_dir($path.'/backend')) {
                Lang::addJsonPath($path.'/backend');
            }
        }
    }

    public static function locale(): string
    {
        return Installation::manifest()['locale'];
    }

    /** Establish fresh installation state for each HTTP request or queued job. */
    public static function apply(): void
    {
        app()->setLocale(self::locale());
    }

    /** Translate system labels only; do not call on names, notes or stored content. */
    public static function text(string $message, array $replace = []): string
    {
        return (string) Lang::get($message, $replace, self::locale());
    }
}
