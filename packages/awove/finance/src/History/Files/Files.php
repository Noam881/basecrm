<?php

namespace Awove\Finance\History\Files;

use Awove\Core\Imports\Metadata;
use Awove\Finance\History\Access;
use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Safe DTOs and downloads only. No private paths, checksums, ledger or raw source metadata DTOs. */
class Files
{
    public function __construct(private Importer $importer, private Storage $storage) {}

    public function forRecord($actor, int $recordId): array
    {
        Access::view($actor, $recordId);
        $files = Attachment::where('record_id', $recordId)->where('state', 'committed')->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($row) => $this->dto($this->importer->readable($actor, $row)))->all();
        Access::view($actor, $recordId);

        return $files;
    }

    public function download($actor, int $recordId, string $id): StreamedResponse
    {
        Access::view($actor, $recordId);
        $row = Attachment::where('record_id', $recordId)->findOrFail(Metadata::uuid($id));
        $row = $this->importer->readable($actor, $row);
        try {
            $stream = $this->storage->openVerified($row);
            try {
                $after = $this->importer->readable($actor, $row);
                abort_unless($row->getAttributes() === $after->getAttributes(), 409, 'Finance file evidence changed.');
                Access::view($actor, $recordId);
            } catch (\Throwable $error) {
                fclose($stream);
                throw $error;
            }

            return response()->streamDownload(function () use ($stream, $actor, $row, $recordId): void {
                try {
                    // StreamedResponse can start later than its construction. Recheck all authority
                    // and the actual opened bytes immediately before any private byte is emitted.
                    $current = $this->importer->readable($actor, $row);
                    abort_unless($row->getAttributes() === $current->getAttributes(), 409, 'Finance file evidence changed.');
                    Access::view($actor, $recordId);
                    $this->storage->verifyStream($current, $stream);
                    $latest = $this->importer->readable($actor, $current);
                    abort_unless($current->getAttributes() === $latest->getAttributes(), 409, 'Finance file evidence changed.');
                    Access::view($actor, $recordId);
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            }, $row->original_name, ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) $row->size_bytes,
                'Cache-Control' => 'private, no-store, max-age=0', 'X-Content-Type-Options' => 'nosniff']);
        } catch (AuthorizationException|HttpExceptionInterface $error) {
            throw $error;
        } catch (\Throwable) {
            throw new \RuntimeException('The financial file could not be read safely.');
        }
    }

    private function dto(Attachment $row): array
    {
        $source = $row->source()->data;

        return ['id' => $row->id, 'entry_id' => $row->entry_id, 'name' => $row->original_name, 'mime_type' => $row->mime_type,
            'size_bytes' => $row->size_bytes, 'imported_at' => $row->committed_at->toISOString(),
            'download_url' => '/finance/history/'.$row->record_id.'/files/'.$row->id.'/download',
            'source' => ['kind' => $source['kind'], 'item_id' => $source['item_id'], 'asset_id' => $source['asset_id'],
                'update_id' => $source['update_id'], 'reply_id' => $source['reply_id'], 'author' => ['name' => $source['author']['name']],
                'created_at' => $source['source_created_at'], 'created_precision' => Source::precision($source['source_created_at']),
                'updated_at' => $source['source_updated_at'], 'updated_precision' => Source::precision($source['source_updated_at'])]];
    }
}
