<?php

namespace Awove\Core\Services;

use Awove\Core\Models\Lead;
use Awove\Core\Models\SignedContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SignedContracts
{
    public function forLead($user, Lead $lead): array
    {
        $lead = Lead::findOrFail($lead->id);
        Gate::forUser($user)->authorize('view', $lead);

        return SignedContract::where('lead_id', $lead->id)->with('creator:id,name')->orderByDesc('version')->get()
            ->map(fn (SignedContract $contract) => $this->metadata($contract))->all();
    }

    public function metadata(SignedContract $contract): array
    {
        return [
            'id' => $contract->id,
            'version' => $contract->version,
            'original_name' => $contract->original_name,
            'created_at' => CarbonImmutable::parse($contract->created_at)->toISOString(),
            'created_by' => $contract->creator?->only(['id', 'name']),
            'download_url' => '/leads/'.$contract->lead_id.'/signed-contracts/'.$contract->id.'/download',
        ];
    }

    public function upload($user, Lead $lead, array $data): SignedContract
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
        $path = 'signed-contracts/'.$lead->id.'/'.Str::uuid().'.'.$extension;
        $disk = Storage::disk('local');
        try {
            return DB::transaction(function () use ($user, $lead, $file, $name, $path, $disk) {
                // Ownership and archive changes use the same parent lock.
                $locked = Lead::lockForUpdate()->findOrFail($lead->id);
                Gate::forUser($user)->authorize('update', $locked);
                $version = ((int) SignedContract::where('lead_id', $locked->id)->max('version')) + 1;
                $stored = $disk->putFileAs(dirname($path), $file, basename($path), ['visibility' => 'private']);
                if ($stored !== $path) {
                    throw new \RuntimeException('The signed contract could not be stored. Please try again.');
                }
                $checksum = hash_file('sha256', $file->getRealPath());
                if ($checksum === false) {
                    throw new \RuntimeException('The signed contract could not be verified. Please try again.');
                }
                $contract = SignedContract::create([
                    'lead_id' => $locked->id,
                    'version' => $version,
                    'original_name' => $name,
                    'private_path' => $path,
                    'mime_type' => $file->getMimeType(),
                    'size_bytes' => $file->getSize(),
                    'sha256' => $checksum,
                    'created_by' => $user->id,
                    'created_at' => now(),
                ]);
                app(Leads::class)->log($locked, $user, 'signed_contract.uploaded', ['signed_contract_id' => $contract->id, 'version' => $version]);

                return $contract;
            });
        } catch (\Throwable $error) {
            // The randomized path belongs only to this attempted upload.
            try {
                if (! $disk->delete($path)) {
                    report(new \RuntimeException('An uncommitted private signed contract file could not be removed.'));
                }
            } catch (\Throwable $cleanupError) {
                report($cleanupError);
            }
            throw $error;
        }
    }

    public function download($user, Lead $lead, SignedContract $contract): StreamedResponse
    {
        $lead = Lead::findOrFail($lead->id);
        Gate::forUser($user)->authorize('view', $lead);
        $contract = SignedContract::where('lead_id', $lead->id)->findOrFail($contract->id);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($contract->private_path), 404, __('This signed contract file is unavailable.'));
        $stream = $disk->readStream($contract->private_path);
        abort_unless(is_resource($stream), 404, __('This signed contract file is unavailable.'));

        return response()->streamDownload(function () use ($stream): void {
            try {
                fpassthru($stream);
            } finally {
                fclose($stream);
            }
        }, $contract->original_name, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) $contract->size_bytes,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeName(string $original, string $extension): string
    {
        $base = pathinfo(basename(str_replace('\\', '/', $original)), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N}._ ()-]+/u', '_', $base), '. ');

        return Str::substr($base !== '' ? $base : 'Signed contract', 0, 170).'.'.$extension;
    }
}
