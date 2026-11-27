<?php

namespace Awove\Vault;

use Awove\Core\Installation;
use Awove\Vault\Imports\MondayFiles;
use Awove\Vault\Imports\TransferStorage;
use Awove\Vault\Models\Attachment;
use Awove\Vault\Models\ImportFileTransfer;
use Awove\Vault\Models\Item;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class Files
{
    public const MAX_BYTES = 10485760;

    private const MIME_TYPES = ['pdf' => ['application/pdf'], 'txt' => ['text/plain'], 'png' => ['image/png'],
        'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'], 'html' => ['text/html']];

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

                $source = $metadata['source'] ?? null;

                return ['id' => $file->id, 'name' => $metadata['name'], 'size_bytes' => $file->size_bytes,
                    'source' => is_array($source) ? ['original_name' => $source['original_name'] ?? null,
                        'author' => ['name' => $source['creator']['name'] ?? null], 'created_at' => $source['created_at'] ?? null] : null];
            })->all();
        app(Unlocks::class)->requireUnlocked($request);
        Access::authorize($user, 'read', $item->fresh());

        return $metadata;
    }

    public function upload(Request $request, string $itemId): Item
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $input = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,txt,png,jpg,jpeg,doc,docx,html', 'extensions:pdf,txt,png,jpg,jpeg,doc,docx,html'], 'version' => 'required|integer|min:1']);
        /** @var UploadedFile $upload */
        $upload = $input['file'];
        // HTML is an encrypted attachment, never a substitute for an existing document format.
        $htmlName = strtolower($upload->getClientOriginalExtension()) === 'html';
        $htmlContent = $upload->getMimeType() === 'text/html';
        if ($htmlName !== $htmlContent) {
            throw ValidationException::withMessages(['file' => __('Choose a supported file whose extension matches its content.')]);
        }
        $path = 'vault/'.hash('sha256', (string) Installation::manifest()['id']).'/'.Str::uuid().'.enc';
        $disk = Storage::disk('local');
        $plain = null;
        try {
            return DB::transaction(function () use ($request, $user, $input, $upload, $itemId, $path, &$plain) {
                $item = Item::where('installation_id', Installation::manifest()['id'])->lockForUpdate()->findOrFail($itemId);
                Access::authorize($user, 'files.upload', $item);
                app(Records::class)->version($item, (int) $input['version']);
                abort_unless($upload->getSize() > 0, 422, __('Choose a non-empty supported document.'));
                $temporary = $upload->getRealPath();
                abort_unless(is_string($temporary) && is_file($temporary) && @chmod($temporary, 0600)
                    && (fileperms($temporary) & 0077) === 0, 503, __('The secure document could not be read.'));
                $plain = file_get_contents($temporary);
                abort_unless(is_string($plain), 422, __('The secure document could not be read.'));

                return $this->persist($request, $item, $plain, $this->information($upload->getClientOriginalName(), $plain), $path);
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

    /** The ordinary encrypted file policy is shared by native uploads and the trusted source adapter. */
    public function information(string $original, #[\SensitiveParameter] string $plain): array
    {
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($plain);
        abort_unless(strlen($plain) > 0 && strlen($plain) <= self::MAX_BYTES && strlen($original) <= 4096 && ! str_contains($original, "\0")
            && isset(self::MIME_TYPES[$extension]) && in_array($mime, self::MIME_TYPES[$extension], true), 422);

        return ['name' => $this->safeName($original), 'mime' => $mime, 'size_bytes' => strlen($plain), 'extension' => $extension];
    }

    public static function supports(string $extension): bool
    {
        return isset(self::MIME_TYPES[$extension]);
    }

    /** @internal No caller-selected path or fake unlock; the retained journal is reloaded immediately. */
    public function uploadReserved(Request $request, ImportFileTransfer $transfer, #[\SensitiveParameter] string $plain, #[\SensitiveParameter] array $source): Item
    {
        abort_unless(DB::transactionLevel() > 0, 409);
        $transfer = app(MondayFiles::class)->writable($request, $transfer, $plain, $source);
        $item = app(Records::class)->find($transfer->item_id);
        app(Records::class)->version($item, $transfer->item_version);

        return $this->persist($request, $item, $plain, $this->information($source['original_name'], $plain), $transfer->private_path, $transfer, $source);
    }

    /** @internal Actual authenticated readback; plaintext never enters an HTTP metadata response. */
    public function inspectStored(Request $request, Attachment $file): array
    {
        $user = Access::actor($request->user());
        app(Unlocks::class)->requireUnlocked($request);
        $item = app(Records::class)->find($file->item_id);
        Access::authorize($user, 'files.download', $item);
        abort_unless($file->installation_id === Installation::manifest()['id'] && $file->deleted_at === null, 409);
        $metadata = json_decode(app(Cipher::class)->decrypt($file->encrypted_name, $file->name_key_version, $this->context($file, 'name')), true, 16, JSON_THROW_ON_ERROR);
        $plain = app(Cipher::class)->decrypt(Storage::disk('local')->get($file->private_path), $file->content_key_version, $this->context($file, 'content'));
        try {
            abort_unless(strlen($plain) === $file->size_bytes && is_array($metadata), 503);
            Access::authorize($user, 'files.download', app(Records::class)->find($item->id));
            app(Unlocks::class)->requireUnlocked($request);

            return ['metadata' => $metadata, 'plaintext' => $plain];
        } catch (\Throwable $error) {
            sodium_memzero($plain);
            throw $error;
        }
    }

    private function persist(Request $request, Item $item, #[\SensitiveParameter] string $plain, array $info, string $path, ?ImportFileTransfer $transfer = null, ?array $source = null): Item
    {
        $user = Access::actor($request->user());
        Access::authorize($user, 'files.upload', $item);
        app(Unlocks::class)->requireUnlocked($request);
        $fileId = $transfer === null ? (string) Str::uuid() : $transfer->file_id;
        $file = new Attachment(['id' => $fileId, 'item_id' => $item->id, 'installation_id' => Installation::manifest()['id']]);
        $cipher = app(Cipher::class);
        $content = $cipher->encrypt($plain, $this->context($file, 'content'));
        $metadata = ['name' => $info['name'], 'mime' => $info['mime'], ...$source === null ? [] : ['source' => $source]];
        $name = $cipher->encrypt(json_encode($metadata, JSON_THROW_ON_ERROR), $this->context($file, 'name'));
        app(Unlocks::class)->requireUnlocked($request);
        Access::authorize($user, 'files.upload', $item->fresh());
        if ($transfer !== null) {
            app(MondayFiles::class)->writable($request, $transfer, $plain, $source);
            app(TransferStorage::class)->write($transfer, $content['ciphertext']);
        } else {
            abort_unless(Storage::disk('local')->put($path, $content['ciphertext'], ['visibility' => 'private']), 503);
        }
        $stored = $cipher->decrypt(Storage::disk('local')->get($path), $content['key_version'], $this->context($file, 'content'));
        try {
            abort_unless(hash_equals(hash('sha256', $plain), hash('sha256', $stored)), 503);
        } finally {
            sodium_memzero($stored);
        }
        $file->fill(['private_path' => $path, 'encrypted_name' => $name['ciphertext'], 'name_key_version' => $name['key_version'],
            'content_key_version' => $content['key_version'], 'size_bytes' => strlen($plain), 'created_by' => $user->id, 'created_at' => now()])->save();
        $item->update(['version' => $item->version + 1, 'updated_by' => $user->id]);
        AuditLog::record($user, 'file.uploaded', $item->id);
        app(Unlocks::class)->requireUnlocked($request);
        Access::authorize($user, 'files.upload', $item->fresh());

        return $item;
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
