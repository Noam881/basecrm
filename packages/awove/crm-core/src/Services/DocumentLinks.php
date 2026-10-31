<?php

namespace Awove\Core\Services;

use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/** Format and redirect only. The owning module must authorize the parent record on every read. */
class DocumentLinks
{
    public function validate(?string $url, string $field = 'document_url'): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }
        $url = trim($url);
        validator([$field => $url], [$field => 'required|string|max:2048|url:https'])->validate();
        $parts = parse_url($url);
        if (! $parts || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\\\\]/', $url)) {
            throw ValidationException::withMessages([$field => 'Use an HTTPS document link without embedded credentials or whitespace.']);
        }

        return $url;
    }

    public function redirect(string $url): RedirectResponse
    {
        $url = $this->validate($url);
        abort_unless($url !== null, 404);

        return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff']);
    }
}
