<?php

namespace Awove\Vault;

use Awove\Core\Installation;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class Files
{
    public function context(Attachment $file, string $purpose): string
    {
        return 'file:'.$file->item_id.':'.$file->id.':'.$purpose;
    }

    public function metadata(Request $request, Item $item): array
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        Access::authorize($user, 'read', $item);

        $metadata = Attachment::where('installation_id', Installation::manifest()['id'])->where('item_id', $item->id)->whereNull('deleted_at')->orderBy('created_at')->get()
            ->map(function (Attachment $file) {
                $metadata = json_decode(app(Cipher::class)->decrypt($file->encrypted_name, $file->name_key_version, $this->context($file, 'name')), true, 8, JSON_THROW_ON_ERROR);

                return ['id' => $file->id, 'name' => $metadata['name'], 'size_bytes' => $file->size_bytes];
            })->all();
        app(Unlocks::class)->requireUnlocked($request);
        Access::authorize($user, 'read', $item->fresh());

        return $metadata;
    }

    public function upload(Request $request, string $itemId): Item
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,txt,png,jpg,jpeg,doc,docx', 'extensions:pdf,txt,png,jpg,jpeg,doc,docx'], 'version' => 'required|integer|min:1']);
        /** @var UploadedFile $upload */
        $upload = $input['file'];
        $path = 'vault/'.hash('sha256', (string) Installation::manifest()['id']).'/'.Str::uuid().'.enc';
        $disk = Storage::disk('local');
        $plain = null;
        try {
            return DB::transaction(function () use ($request, $user, $input, $upload, $itemId, $path, $disk, &$plain) {
                $item = Item::where('installation_id', Installation::manifest()['id'])->lockForUpdate()->findOrFail($itemId);
                Access::authorize($user, 'files.upload', $item);
                app(Records::class)->version($item, (int) $input['version']);
                abort_unless($upload->getSize() > 0, 422, __('Choose a non-empty supported document.'));
                $temporary = $upload->getRealPath();
                abort_unless(is_string($temporary) && is_file($temporary) && @chmod($temporary, 0600)
                    && (fileperms($temporary) & 0077) === 0, 503, __('The secure document could not be read.'));
                $plain = file_get_contents($temporary);
                abort_unless(is_string($plain), 422, __('The secure document could not be read.'));
                $file = new Attachment(['id' => (string) Str::uuid(), 'item_id' => $item->id, 'installation_id' => Installation::manifest()['id']]);
                $cipher = app(Cipher::class);
                $content = $cipher->encrypt($plain, $this->context($file, 'content'));
                $name = $cipher->encrypt(json_encode(['name' => $this->safeName($upload->getClientOriginalName()), 'mime' => $upload->getMimeType()], JSON_THROW_ON_ERROR), $this->context($file, 'name'));
                app(Unlocks::class)->requireUnlocked($request);
                Access::authorize($user, 'files.upload', $item->fresh());
                if (! $disk->put($path, $content['ciphertext'], ['visibility' => 'private'])) {
                    abort(503, __('The encrypted document could not be stored.'));
                }
                $file->fill(['private_path' => $path, 'encrypted_name' => $name['ciphertext'], 'name_key_version' => $name['key_version'],
                    'content_key_version' => $content['key_version'], 'size_bytes' => strlen($plain), 'created_by' => $user->id, 'created_at' => now()])->save();
                $item->update(['version' => $item->version + 1, 'updated_by' => $user->id]);
                AuditLog::record($user, 'file.uploaded', $item->id);
                app(Unlocks::class)->requireUnlocked($request);
                Access::authorize($user, 'files.upload', $item->fresh());

                return $item;
            });
        } catch (\Throwable $error) {
            // This random path is owned only by this attempted upload.
            $disk->delete($path);
            throw $error;
        } finally {
            if (is_string($plain)) {
                sodium_memzero($plain);
            }
            // PHP uploads are private temporary inputs, never plaintext document storage.
            $temporary = $upload->getRealPath();
            if (is_string($temporary) && is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    public function download(Request $request, string $id): StreamedResponse
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $file = $this->find($id);
        $item = app(Records::class)->find($file->item_id);
        Access::authorize($user, 'files.download', $item);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($file->private_path), 404, __('This encrypted document is unavailable.'));
        $name = json_decode(app(Cipher::class)->decrypt($file->encrypted_name, $file->name_key_version, $this->context($file, 'name')), true, 8, JSON_THROW_ON_ERROR);
        $plain = app(Cipher::class)->decrypt($disk->get($file->private_path), $file->content_key_version, $this->context($file, 'content'));
        abort_unless(strlen($plain) === $file->size_bytes, 503, __('Vault encryption is unavailable. Contact the installation operator.'));
        Access::authorize($user, 'files.download', app(Records::class)->find($file->item_id));
        app(Unlocks::class)->requireUnlocked($request);

        return response()->streamDownload(function () use (&$plain, $request, $user, $file) {
            try {
                Access::authorize($user, 'files.download', app(Records::class)->find($file->item_id));
                app(Unlocks::class)->requireUnlocked($request);
                AuditLog::record($user, 'file.downloaded', $file->item_id);
                echo $plain;
            } catch (\Throwable $error) {
                AuditLog::record($user, 'file.downloaded', $file->item_id, 'denied');
                throw $error;
            } finally {
                sodium_memzero($plain);
            }
        }, $name['name'], ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $file->size_bytes,
            'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer']);
    }

    public function remove(Request $request, string $id): Item
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $input = $request->validate(['version' => 'required|integer|min:1']);

        return DB::transaction(function () use ($request, $user, $input, $id) {
            $file = $this->find($id);
            $item = Item::where('installation_id', Installation::manifest()['id'])->lockForUpdate()->findOrFail($file->item_id);
            $file = $this->find($id);
            Access::authorize($user, 'files.delete', $item);
            app(Records::class)->version($item, (int) $input['version']);
            app(Unlocks::class)->requireUnlocked($request);
            $file->update(['deleted_at' => now()]);
            $item->update(['version' => $item->version + 1, 'updated_by' => $user->id]);
            AuditLog::record($user, 'file.deleted', $item->id);
            app(Unlocks::class)->requireUnlocked($request);
            Access::authorize($user, 'files.delete', $item->fresh());
            // Removal is logical retention, consistent with item/audit backup recovery.

            return $item;
        });
    }

    private function find(string $id): Attachment
    {
        return Attachment::where('installation_id', Installation::manifest()['id'])->whereNull('deleted_at')->findOrFail($id);
    }

    private function safeName(string $original): string
    {
        $name = basename(str_replace('\\', '/', $original));
        $name = (string) preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $name);

        return Str::substr(trim($name, '. '), 0, 180) ?: 'Document';
    }
}
