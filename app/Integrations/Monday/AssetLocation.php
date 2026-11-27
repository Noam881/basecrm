<?php

namespace App\Integrations\Monday;

use SensitiveParameter;

/** Ephemeral server-derived location. Scope, grant and file-format authority remain with its owning adapter. */
final readonly class AssetLocation
{
    private function __construct(private string $assetId, private string $filename, private string $fileExtension, private int $bytes, #[SensitiveParameter] private string $url) {}

    public static function fromSource(string $id, #[SensitiveParameter] string $name, string $extension, int $size, #[SensitiveParameter] string $publicUrl): self
    {
        if (! preg_match('/^[1-9][0-9]{0,63}$/D', $id) || ! mb_check_encoding($name, 'UTF-8') || $name === '' || strlen($name) > 4096
            || preg_match('/[\x00-\x1F\x7F]/u', $name) || ! mb_check_encoding($extension, 'UTF-8') || strlen($extension) > 128
            || preg_match('/[\x00-\x1F\x7F]/u', $extension) || $size < 1 || $size > 104857600 || strlen($publicUrl) > 16384
            || preg_match('/[\x00-\x20\x7F]/', $publicUrl)) {
            throw new AssetDownloadFailed('source_asset_invalid');
        }

        return new self($id, $name, $extension, $size, $publicUrl);
    }

    public function id(): string
    {
        return $this->assetId;
    }

    public function name(): string
    {
        return $this->filename;
    }

    public function extension(): string
    {
        return $this->fileExtension;
    }

    public function size(): int
    {
        return $this->bytes;
    }

    /** Signed capability URL: never persist, serialize, render or log it. */
    public function sourceUrl(): string
    {
        return $this->url;
    }

    public function __debugInfo(): array
    {
        return ['id' => $this->assetId, 'size' => $this->bytes];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Source file locations are not serializable.');
    }
}
