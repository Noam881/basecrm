<?php

namespace Awove\Vault;

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\Models\Member;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Read-only configuration evidence; deliberately cannot certify live delivery or backup custody. */
final class Readiness
{
    public static function installationConfirmed(?string $confirmation): bool
    {
        try {
            $id = Installation::manifest()['id'];

            return is_string($id) && $id !== '' && $confirmation === $id && config('core.installation') === $id;
        } catch (\Throwable) {
            return false;
        }
    }

    /** Shared strict boundary for the installation-wide session reset. No identifiers are returned. */
    public static function databaseBoundary(): bool
    {
        $default = config('database.default');
        if ($default !== 'pgsql' || config('session.driver') !== 'database' || config('session.table') !== 'sessions'
            || ! in_array(config('session.connection'), [null, $default], true)
            || config('queue.connections.database.driver') !== 'database'
            || ! in_array(config('queue.connections.database.connection'), [null, $default], true)) {
            return false;
        }
        try {
            $connection = DB::connection();
            $actual = $connection->selectOne('SELECT current_database() AS database_name, current_user AS role_name');

            return $connection->getDriverName() === 'pgsql'
                && $actual->database_name === $connection->getConfig('database')
                && $actual->role_name === $connection->getConfig('username');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function schemaReady(): bool
    {
        foreach (['vault_members', 'vault_unlocks', 'vault_challenges', 'vault_recovery_codes', 'vault_items', 'vault_files', 'vault_audits', 'sessions', 'magic_login_tokens', 'jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                return false;
            }
        }

        return true;
    }

    public static function installationDataMatches(): bool
    {
        foreach (['vault_members', 'vault_unlocks', 'vault_challenges', 'vault_items', 'vault_files', 'vault_audits'] as $table) {
            if (DB::table($table)->where('installation_id', '!=', Installation::manifest()['id'])->exists()) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string,bool> */
    public function report(?string $confirmation, int $minimumAdmins = 1): array
    {
        $checks = array_fill_keys(['installation_confirmed', 'module_enabled', 'production_runtime', 'https_url_configured',
            'debug_disabled', 'application_key_configured', 'secure_database_sessions', 'database_boundary_verified', 'notice_queue_configured',
            'sodium_available', 'argon2id_available', 'external_protected_keyring', 'encryption_configured',
            'private_local_storage', 'delivering_mail_transport_configured', 'development_question_disabled',
            'schema_ready', 'vault_installation_scope_verified', 'minimum_full_admins_enrolled'], false);
        $checks['installation_confirmed'] = self::installationConfirmed($confirmation);
        if ($checks['installation_confirmed'] && $minimumAdmins > 0) {
            $checks['module_enabled'] = $this->check(fn () => app(Modules::class)->enabled('vault'));
            $checks['production_runtime'] = app()->isProduction();
            $checks['debug_disabled'] = config('app.debug') === false;
            $checks['application_key_configured'] = $this->check(fn () => app('encrypter')->getKey() !== '');
            $url = parse_url((string) config('app.url'));
            $checks['https_url_configured'] = is_array($url) && ($url['scheme'] ?? null) === 'https'
                && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass'])
                && ! isset($url['query']) && ! isset($url['fragment'])
                && ! in_array(strtolower($url['host']), ['localhost', '127.0.0.1', '[::1]'], true)
                && ! preg_match('/\.(?:test|invalid|localhost)$/iD', $url['host']);
            $checks['secure_database_sessions'] = config('session.driver') === 'database' && config('session.encrypt') === true
                && config('session.secure') === true && config('session.http_only') === true
                && in_array(config('session.same_site'), ['lax', 'strict'], true);
            $checks['database_boundary_verified'] = self::databaseBoundary();
            $checks['notice_queue_configured'] = config('queue.default') === 'database'
                && config('queue.connections.database.driver') === 'database'
                && in_array(config('queue.connections.database.connection'), [null, config('database.default')], true);
            $checks['sodium_available'] = extension_loaded('sodium');
            $checks['argon2id_available'] = in_array('argon2id', password_algos(), true);
            $checks['external_protected_keyring'] = $this->check(fn () => $this->protectedKeyring());
            $checks['encryption_configured'] = $this->check(fn () => app(Cipher::class)->configured());
            $checks['private_local_storage'] = $this->check(fn () => $this->privateStorage());
            $checks['delivering_mail_transport_configured'] = $this->check(function () {
                Unlocks::assertMailTransport();

                return ! app()->environment('testing');
            });
            $checks['development_question_disabled'] = ! config('vault.development_question', false) && ! config('vault.fictional_only', false);
            $checks['schema_ready'] = $this->check(fn () => self::schemaReady());
            if ($checks['schema_ready']) {
                $checks['vault_installation_scope_verified'] = $this->check(fn () => self::installationDataMatches());
                $checks['minimum_full_admins_enrolled'] = $this->check(fn () => $this->fullAdministrators() >= $minimumAdmins);
            }
        }

        return [...$checks, 'automated_checks_passed' => ! in_array(false, $checks, true),
            'mail_delivery_verified' => false, 'separate_key_backup_verified' => false, 'restore_rehearsal_verified' => false,
            'proxy_tls_and_monitoring_verified' => false, 'post_snapshot_authority_reconciled' => false,
            'manual_verification_required' => true];
    }

    private function check(callable $check): bool
    {
        try {
            return (bool) $check();
        } catch (\Throwable) {
            // Never report exception details, paths, credentials or account identities.
            return false;
        }
    }

    private function protectedKeyring(): bool
    {
        $path = config('vault.key_file');
        if (! is_string($path) || ! is_file($path) || is_link($path) || ! is_readable($path) || ! KeyPaths::external($path)) {
            return false;
        }
        $parent = realpath(dirname($path));

        return $parent !== false && (fileperms($path) & 0777) === 0600 && (fileperms($parent) & 0077) === 0;
    }

    private function privateStorage(): bool
    {
        $root = config('filesystems.disks.local.root');
        if (config('filesystems.disks.local.driver') !== 'local' || ! is_string($root) || ! is_dir($root) || ! is_readable($root) || ! is_writable($root)) {
            return false;
        }
        $root = realpath($root);
        $public = realpath(public_path());

        return $root !== false && $public !== false && $root !== $public && ! str_starts_with($root, $public.'/')
            && (fileperms($root) & 0077) === 0;
    }

    private function fullAdministrators(): int
    {
        $permissions = app(Modules::class)->installed()['vault']->permissions();
        $count = 0;
        foreach (Member::where('installation_id', Installation::manifest()['id'])->whereNotNull('confirmed_at')
            ->whereNotNull('password_hash')->whereNotNull('encrypted_totp')->whereNotNull('totp_key_version')->get() as $member) {
            $user = User::find($member->user_id);
            if ($user && $user->active && $user->hasVerifiedEmail() && $user->hasAllPermissions($permissions)
                && $user->can('identities.manage')) {
                $count++;
            }
        }

        return $count;
    }
}
