<?php

namespace App\Integrations\Monday;

/** The first migration step deliberately cannot query boards or customer data. */
final class Connection
{
    public const API_VERSION = ReadOnlyClient::API_VERSION;

    public const QUERY = 'query BaseCrmConnectionCheck { me { id account { id name slug } } version { value } }';

    public function __construct(private readonly ReadOnlyClient $client) {}

    /** @return array{ok: bool, error?: string, api_version?: string, account?: array{id: string, name: string, slug: string}} */
    public function check(): array
    {
        $result = $this->client->query(self::QUERY);
        if (! $result['ok']) {
            return $this->failure($result['error']);
        }
        $data = $result['data'];
        $me = $data['me'] ?? null;
        $account = is_array($me) ? ($me['account'] ?? null) : null;
        if (! is_array($me) || $this->identifier($me['id'] ?? null) === null || ! is_array($account)
            || ($id = $this->identifier($account['id'] ?? null)) === null
            || ! $this->metadata($account['name'] ?? null, 512) || ! $this->metadata($account['slug'] ?? null, 255)) {
            return $this->failure('invalid_response');
        }
        if (($data['version']['value'] ?? null) !== self::API_VERSION) {
            return $this->failure('version_mismatch');
        }

        return ['ok' => true, 'api_version' => self::API_VERSION,
            'account' => ['id' => $id, 'name' => $account['name'], 'slug' => $account['slug']]];
    }

    private function identifier(mixed $value): ?string
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-9][0-9]{0,63}$/D', (string) $value)
            ? (string) $value : null;
    }

    private function metadata(mixed $value, int $maximum): bool
    {
        return is_string($value) && trim($value) !== '' && mb_strlen($value) <= $maximum
            && ! preg_match('/[\x00-\x1F\x7F]/u', $value);
    }

    /** @return array{ok: false, error: string} */
    private function failure(string $category): array
    {
        return ['ok' => false, 'error' => $category];
    }
}
