<?php

namespace Awove\Core\Services;

use Awove\Core\Models\Lead;
use Awove\Core\Models\Proposal;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Proposals
{
    public function forLead($user, Lead $lead): array
    {
        Gate::forUser($user)->authorize('view', $lead);

        return Proposal::where('lead_id', $lead->id)->with('uploader:id,name')->orderByDesc('version')->get()
            ->map(fn (Proposal $proposal) => $this->metadata($proposal))->all();
    }

    public function metadata(Proposal $proposal): array
    {
        return [
            'id' => $proposal->id,
            'version' => $proposal->version,
            'original_name' => $proposal->original_name,
            'mime_type' => $proposal->mime_type,
            'size_bytes' => $proposal->size_bytes,
            'created_at' => CarbonImmutable::parse($proposal->created_at)->toISOString(),
            'uploaded_by' => $proposal->uploader?->only(['id', 'name']),
            'download_url' => '/leads/'.$proposal->lead_id.'/proposals/'.$proposal->id.'/download',
        ];
    }

    public function upload($user, Lead $lead, array $data): Proposal
    {
        Gate::forUser($user)->authorize('update', $lead);
        $validated = validator($data, ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,doc,docx', 'extensions:pdf,doc,docx']])->validate();
        /** @var UploadedFile $file */
        $file = $validated['file'];
        $extension = strtolower($file->getClientOriginalExtension());
        if ($file->guessExtension() !== $extension || $file->getSize() < 1) {
            throw ValidationException::withMessages(['file' => __('Choose a valid PDF, DOC or DOCX document (up to 10 MB).')]);
        }
        $name = $this->safeName($file->getClientOriginalName(), $extension);
        $path = 'proposals/'.$lead->id.'/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk('local');
        try {
            return DB::transaction(function () use ($user, $lead, $file, $name, $path, $disk) {
                // Ownership and archive changes use the same parent lock.
                $locked = Lead::lockForUpdate()->findOrFail($lead->id);
                Gate::forUser($user)->authorize('update', $locked);
                $version = ((int) Proposal::where('lead_id', $locked->id)->max('version')) + 1;
                $stored = $disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                if ($stored !== $path) {
                    throw new \RuntimeException('The proposal could not be stored. Please try again.');
                }
                $checksum = hash_file('sha256', $file->getRealPath());
                if ($checksum === false) {
                    throw new \RuntimeException('The proposal could not be verified. Please try again.');
                }
                $proposal = Proposal::create([
                    'lead_id' => $locked->id,
                    'version' => $version,
                    'original_name' => $name,
                    'private_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => $checksum,
                    'uploaded_by' => $user->id,
                    'created_at' => now(),
                ]);
                app(Leads::class)->log($locked, $user, 'proposal.uploaded', ['proposal_id' => $proposal->id, 'version' => $version]);

                return $proposal;
            });
        } catch (\Throwable $error) {
            // The randomized path belongs only to this attempted upload.
            try {
                if (! $disk->delete($path)) {
                    report(new \RuntimeException('An uncommitted private proposal file could not be removed.'));
                }
            } catch (\Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $error;
        }
    }

    public function download($user, Lead $lead, Proposal $proposal): StreamedResponse
    {
        Gate::forUser($user)->authorize('view', $lead);
        abort_unless($proposal->lead_id === $lead->id, 404);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($proposal->private_path), 404, __('This proposal file is unavailable.'));
        $stream = $disk->readStream($proposal->private_path);
        abort_unless(is_resource($stream), 404, __('This proposal file is unavailable.'));

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $proposal->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $proposal->size_bytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeName(string $original, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $original)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $base), '. ');

        return Str::substr($base !== '' ? $base : 'Proposal', 0, 170).'.'.$extension;
    }
}
