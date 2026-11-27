<?php

namespace Awove\Core\Imports;

use Awove\Core\Installation;
use Illuminate\Support\Facades\DB;

/** Stable installation/connection identity, deliberately independent of deploy/release paths. */
final class Runtime
{
    public static function current(): array
    {
        try {
            return self::inspect();
        } catch (\Throwable) {
            // Connection/configuration failures may contain host, database or account details.
            abort(409, 'Import runtime boundary changed.');
        }
    }

    private static function inspect(): array
    {
        $installation = Metadata::opaque(Installation::manifest()['id'] ?? null, 64);
        abort_unless(config('core.installation') === $installation && config('database.default') === 'pgsql', 409, 'Import runtime boundary changed.');
        $connection = DB::connection();
        abort_unless($connection->getDriverName() === 'pgsql', 409, 'Import runtime boundary changed.');
        $actual = $connection->selectOne('SELECT current_database() AS database_name, current_user AS role_name, current_schema() AS schema_name');
        abort_unless($actual && $actual->database_name === $connection->getConfig('database') && $actual->role_name === $connection->getConfig('username'), 409, 'Import runtime boundary changed.');
        $url = parse_url((string) config('app.url'));
        abort_unless(is_array($url) && in_array($url['scheme'] ?? null, ['http', 'https'], true) && isset($url['host'])
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment']), 409, 'Import runtime boundary changed.');
        $context = ['v' => 1, 'installation' => $installation, 'environment' => app()->environment(),
            'origin' => [strtolower($url['scheme']), strtolower($url['host']), $url['port'] ?? null, rtrim($url['path'] ?? '', '/')],
            'database' => [$connection->getConfig('host'), (string) $connection->getConfig('port'), $actual->database_name, $actual->role_name, $actual->schema_name]];

        return ['installation_id' => $installation, 'boundary' => hash_hmac('sha256', json_encode($context, JSON_THROW_ON_ERROR), app('encrypter')->getKey())];
    }
}
