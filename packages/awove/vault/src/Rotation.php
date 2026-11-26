<?php

namespace Awove\Vault;

use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\Item;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Explicit operator rotation stages new ciphertext; existing bytes survive rollback. */
final class Rotation
{
    public function run(bool $apply = false): array
    {
        abort_unless(app(Modules::class)->enabled('vault'), 404);
        $install = Installation::manifest()['id'];
        $cipher = app(Cipher::class);
        $active = $cipher->activeVersion();
        $staged = [];
        $retired = [];
        $disk = Storage::disk('local');
        try {
            $result = DB::transaction(function () use ($install, $cipher, $active, $apply, $disk, &$staged, &$retired) {
                DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['vault-rotation:'.$install]);
                $counts = ['items' => 0, 'files' => 0, 'factors' => 0, 'active_key' => $active, 'applied' => $apply];
                // Locked items make edits/uploads wait until the complete rotation commits.
                foreach (Item::withTrashed()->where('installation_id', $install)->orderBy('id')->lockForUpdate()->get() as $item) {
                    $plain = $cipher->decrypt($item->encrypted_payload, $item->key_version, 'item:'.$item->id);
                    try {
                        if ($item->key_version !== $active) {
                            $counts['items']++;
                            if ($apply) {
                                $sealed = $cipher->encrypt($plain, 'item:'.$item->id);
                                $item->update(['encrypted_payload' => $sealed['ciphertext'], 'key_version' => $sealed['key_version']]);
                            }
                        }
                    } finally {
                        sodium_memzero($plain);
                    }
                }
                foreach (Attachment::where('installation_id', $install)->orderBy('id')->lockForUpdate()->get() as $file) {
                    $files = app(Files::class);
                    abort_unless($disk->exists($file->private_path), 503, __('An encrypted Vault document is unavailable. Rotation stopped.'));
                    $name = $cipher->decrypt($file->encrypted_name, $file->name_key_version, $files->context($file, 'name'));
                    $plain = $cipher->decrypt($disk->get($file->private_path), $file->content_key_version, $files->context($file, 'content'));
                    try {
                        if ($file->name_key_version !== $active || $file->content_key_version !== $active) {
                            $counts['files']++;
                            if ($apply) {
                                $newName = $cipher->encrypt($name, $files->context($file, 'name'));
                                $content = $cipher->encrypt($plain, $files->context($file, 'content'));
                                $path = 'vault/'.hash('sha256', (string) $install).'/'.Str::uuid().'.enc';
                                $staged[] = $path;
                                abort_unless($disk->put($path, $content['ciphertext'], ['visibility' => 'private']), 503, __('Encrypted Vault rotation could not be stored.'));
                                $retired[] = $file->private_path;
                                $file->update(['encrypted_name' => $newName['ciphertext'], 'name_key_version' => $newName['key_version'], 'content_key_version' => $content['key_version'], 'private_path' => $path]);
                            }
                        }
                    } finally {
                        sodium_memzero($name);
                        sodium_memzero($plain);
                    }
                }
                if (Schema::hasTable('vault_members')) {
                    foreach (DB::table('vault_members')->where('installation_id', $install)->orderBy('id')->lockForUpdate()->get() as $member) {
                        foreach ([['encrypted_totp', 'totp_key_version', 'totp'], ['pending_encrypted_totp', 'pending_totp_key_version', 'pending-totp']] as [$field, $key, $purpose]) {
                            if (! $member->$field) {
                                continue;
                            }
                            $plain = $cipher->decrypt($member->$field, $member->$key, 'member:'.$member->user_id.':'.$purpose);
                            try {
                                if ($active !== $member->$key) {
                                    $counts['factors']++;
                                    if ($apply) {
                                        $sealed = $cipher->encrypt($plain, 'member:'.$member->user_id.':'.$purpose);
                                        DB::table('vault_members')->where('id', $member->id)->update([$field => $sealed['ciphertext'], $key => $sealed['key_version']]);
                                    }
                                }
                            } finally {
                                sodium_memzero($plain);
                            }
                        }
                    }
                }
                if ($apply) {
                    AuditLog::record(null, 'keys.rotated');
                }

                return $counts;
            });
        } catch (\Throwable $error) {
            // Existing referenced files are untouched on failed transactions.
            foreach ($staged as $path) {
                $disk->delete($path);
            }
            throw $error;
        }
        // Only successfully replaced encrypted blobs are retired after the commit.
        DB::afterCommit(function () use ($retired, $disk) {
            foreach ($retired as $path) {
                if (! $disk->delete($path)) {
                    // No filename, plaintext or key material enters application logs.
                    report(new \RuntimeException('A retired encrypted Vault blob could not be removed.'));
                }
            }
        });

        return $result;
    }
}
