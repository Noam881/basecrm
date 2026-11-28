<?php

namespace Awove\Core\Services;

use Awove\Core\Imports\DocumentImporter;
use Awove\Core\Imports\DocumentTransfer;
use Awove\Core\Imports\DocumentTransferStorage;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Document;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Shared private client attachments; proposals, signed contracts and Vault files keep their own meanings. */
class Documents
{
    public const MAX_KILOBYTES = 102400;

    private const MIME_TYPES = [
        'pdf' => ['application/pdf'],
        'doc' => ['application/msword', 'application/x-ole-storage'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls' => ['application/vnd.ms-excel', 'application/x-ole-storage'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'gif' => ['image/gif'],
        'webp' => ['image/webp'],
        'zip' => ['application/zip', 'application/x-zip-compressed'],
        'mp4' => ['video/mp4'],
    ];

    public function forIdentity($user, string $type, int $id): array
    {
        $user = $this->actor($user);
        $identity = $this->identity($type, $id);
        Gate::forUser($user)->authorize('view', $identity);
        $key = $identity instanceof Contact ? 'contact_id' : 'company_id';
        $documents = Document::where($key, $identity->id)->with(['uploader:id,name', 'importTransfer'])
            ->orderByDesc('created_at')->orderByDesc('id')->get()
            ->filter(fn (Document $document) => app(DocumentImporter::class)->canRead($user, $document))->values()
            ->map(fn (Document $document) => $this->metadata($user, $document, $type, $identity->id))->all();

        return ['documents' => $documents, 'can_upload' => Gate::forUser($user)->allows('update', $identity)];
    }

    public function upload($user, string $type, int $id, array $input): Document
    {
        $user = $this->actor($user);
        Gate::forUser($user)->authorize('update', $this->identity($type, $id));
        $data = validator($input, ['file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES]])->validate();
        /** @var UploadedFile $file */
        $file = $data['file'];
        $info = $this->fileInfo($file);
        $path = 'documents/'.$type.'/'.$id.'/'.Str::uuid().'.'.$info['extension'];
        $disk = Storage::disk('local');
        try {
            $sourceChecksum = hash_file('sha256', $file->getRealPath());
            if ($sourceChecksum === false) {
                throw new \RuntimeException;
            }

            return DB::transaction(fn () => $this->persist($user, $type, $id, $file, $info, $path, $sourceChecksum));
        } catch (\Throwable $error) {
            // This random path belongs only to this attempt, never to a retained document.
            try {
                if (! $disk->delete($path)) {
                    report(new \RuntimeException('An uncommitted private document file could not be removed.'));
                }
            } catch (\Throwable) {
                report(new \RuntimeException('An uncommitted private document file could not be removed.'));
            }
            if ($error instanceof AuthorizationException || $error instanceof HttpExceptionInterface) {
                throw $error;
            }
            // Backend storage/database exceptions may contain private URLs or payloads.
            throw new \RuntimeException((string) __('The document could not be stored. Please try again.'));
        }
    }

    /** @internal Reuses the ordinary file policy before a durable import reservation, without storing bytes. */
    public function inspectFile(UploadedFile $file): array
    {
        validator(['file' => $file], ['file' => ['required', 'file', 'max:'.self::MAX_KILOBYTES]])->validate();
        $info = $this->fileInfo($file);
        try {
            $checksum = hash_file('sha256', $file->getRealPath());
            if (! is_string($checksum)) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            throw new \RuntimeException('The document bytes could not be validated.');
        }

        return [...$info, 'sha256' => $checksum];
    }

    /** @internal Only the coordinator's fresh locked journal can choose a reserved path; errors never delete it. */
    public function uploadReserved($user, DocumentTransfer $transfer, UploadedFile $file): Document
    {
        abort_unless(DB::transactionLevel() > 0, 409, 'A document import transaction is required.');
        $transfer = app(DocumentImporter::class)->writable($user, $transfer);
        $info = $this->inspectFile($file);
        abort_unless($info['name'] === $transfer->original_name && $info['mime'] === $transfer->mime_type
            && $info['size'] === $transfer->size_bytes && hash_equals($transfer->sha256, $info['sha256']), 409, 'Document source bytes changed.');
        $type = $transfer->contact_id !== null ? 'contacts' : 'companies';
        $id = $transfer->contact_id ?? $transfer->company_id;

        return $this->persist($user, $type, $id, $file, $info, $transfer->private_path, $info['sha256'], $transfer);
    }

    /** @internal Readback proof for a journal-bound replay; it does not fetch or replace bytes. */
    public function verifyStored(Document $document): void
    {
        $this->verifyBytes($document->private_path, $document->size_bytes, $document->sha256);
    }

    private function fileInfo(UploadedFile $file): array
    {
        $name = $this->filename($file->getClientOriginalName());
        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $mime = $file->getMimeType();
        $size = $file->getSize();
        if (! isset(self::MIME_TYPES[$extension]) || ! in_array($mime, self::MIME_TYPES[$extension], true) || $size === false || $size < 1) {
            $this->invalidFile();
        }

        return ['name' => $name, 'extension' => $extension, 'mime' => $mime, 'size' => $size];
    }

    private function persist($user, string $type, int $id, UploadedFile $file, array $info, string $path, string $checksum, ?DocumentTransfer $transfer = null): Document
    {
        $identity = $this->identity($type, $id, true);
        $actor = $this->actor($user);
        Gate::forUser($actor)->authorize('update', $identity);
        if ($transfer !== null) {
            app(DocumentTransferStorage::class)->copyExclusive($transfer, $file);
        } elseif (Storage::disk('local')->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']) !== $path) {
            throw new \RuntimeException;
        }
        $this->verifyBytes($path, $info['size'], $checksum);
        Gate::forUser($this->actor($actor))->authorize('update', $identity);

        return Document::create(['contact_id' => $identity instanceof Contact ? $identity->id : null, 'company_id' => $identity instanceof Company ? $identity->id : null,
            'original_name' => $info['name'], 'private_path' => $path, 'mime_type' => $info['mime'], 'size_bytes' => $info['size'], 'sha256' => $checksum,
            'uploaded_by' => $actor->id, 'created_at' => now()]);
    }

    private function verifyBytes(string $path, int $size, string $checksum): void
    {
        $stream = Storage::disk('local')->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Document byte readback failed.');
        }
        try {
            $hash = hash_init('sha256');
            $storedSize = hash_update_stream($hash, $stream, $size + 1);
            $storedChecksum = hash_final($hash);
        } finally {
            fclose($stream);
        }
        if ($storedSize !== $size || ! hash_equals($checksum, $storedChecksum)) {
            throw new \RuntimeException('Document byte readback failed.');
        }
    }

    public function download($user, string $type, int $id, Document $document): StreamedResponse
    {
        $document = $this->downloadableDocument($user, $type, $id, $document->id);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->private_path), 404, __('This document file is unavailable.'));
        $stream = $disk->readStream($document->private_path);
        abort_unless(is_resource($stream), 404, __('This document file is unavailable.'));

        return response()->streamDownload(function () use ($stream, $user, $type, $id, $document): void {
            try {
                $this->downloadableDocument($user, $type, $id, $document->id);
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $document->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $document->size_bytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function downloadableDocument($user, string $type, int $id, int $documentId): Document
    {
        $identity = $this->identity($type, $id);
        Gate::forUser($this->actor($user))->authorize('view', $identity);
        $document = Document::findOrFail($documentId);
        abort_unless($identity instanceof Contact ? $document->contact_id === $identity->id : $document->company_id === $identity->id, 404);
        app(DocumentImporter::class)->authorizeRead($user, $document);

        return $document;
    }

    private function metadata($actor, Document $document, string $type, int $id): array
    {
        return ['id' => $document->id, 'original_name' => $document->original_name,
            'mime_type' => $document->mime_type, 'size_bytes' => $document->size_bytes,
            'created_at' => CarbonImmutable::parse($document->created_at)->toISOString(),
            'uploaded_by' => $document->uploader?->only(['id', 'name']),
            'download_url' => '/people/'.$type.'/'.$id.'/documents/'.$document->id.'/download',
            'source' => app(DocumentImporter::class)->sourceMetadata($actor, $document)];
    }

    private function identity(string $type, int $id, bool $lock = false): Contact|Company
    {
        $model = app(Identities::class)->model($type);

        return $model::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
    }

    private function actor($user)
    {
        $actor = $user?->fresh();
        abort_unless($actor && $actor->active, 403);

        return $actor;
    }

    private function filename(string $name): string
    {
        if ($name === '' || ! mb_check_encoding($name, 'UTF-8') || mb_strlen($name) > 255
            || in_array($name, ['.', '..'], true) || preg_match('/[\\\\\/\x00-\x1F\x{007F}-\x{009F}]/u', $name)) {
            $this->invalidFile();
        }

        return $name;
    }

    private function invalidFile(): never
    {
        throw ValidationException::withMessages(['file' => __('Choose a valid document up to 100 MB.')]);
    }
}
