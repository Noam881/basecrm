<?php

namespace App\Integrations\Monday;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** The first migration step deliberately cannot query boards or customer data. */
final class Connection
{
    public const API_VERSION = '2026-10';

    public const QUERY = 'query BaseCrmConnectionCheck { me { id account { id name slug } } version { value } }';

    private const MAX_RESPONSE_BYTES = 65536;

    /** @return array{ok: bool, error?: string, api_version?: string, account?: array{id: string, name: string, slug: string}} */
    public function check(): array
    {
        $token = config('services.monday.token');
        $version = config('services.monday.api_version');
        if (! is_string($token) || trim($token) === '') {
            return $this->failure('token_missing');
        }
        if ($token !== trim($token) || preg_match('/[\r\n\x00]/', $token) || $version !== self::API_VERSION) {
            return $this->failure('configuration_error');
        }

        try {
            $deadline = hrtime(true) + 20_000_000_000;
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['Authorization' => $token, 'API-Version' => $version])
                ->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false, 'verify' => true, 'stream' => true, 'read_timeout' => 2])
                ->post('https://api.monday.com/v2', ['query' => self::QUERY]);

            try {
                if (! $response->successful()) {
                    return $this->failure(match ($response->status()) {
                        401 => 'authentication_failed',
                        403 => 'access_denied',
                        429 => 'rate_limited',
                        default => 'http_error',
                    });
                }
                if ($response->header('API-Version') !== $version) {
                    return $this->failure('version_mismatch');
                }

                $raw = $this->boundedBody($response, $deadline);
                if ($raw === null) {
                    return $this->failure('response_too_large');
                }
                try {
                    $payload = json_decode($raw, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (JsonException) {
                    return $this->failure('invalid_response');
                }
                if (! is_array($payload) || array_is_list($payload)) {
                    return $this->failure('invalid_response');
                }
                // A response containing partial data and errors is not successful.
                if (array_key_exists('errors', $payload)) {
                    if (! is_array($payload['errors'])) {
                        return $this->failure('invalid_response');
                    }
                    if ($payload['errors'] !== []) {
                        return $this->failure($this->apiError($payload['errors']));
                    }
                }

                $me = $payload['data']['me'] ?? null;
                $account = is_array($me) ? ($me['account'] ?? null) : null;
                if (! is_array($me) || $this->identifier($me['id'] ?? null) === null || ! is_array($account)
                    || ($id = $this->identifier($account['id'] ?? null)) === null
                    || ! $this->metadata($account['name'] ?? null, 512) || ! $this->metadata($account['slug'] ?? null, 255)) {
                    return $this->failure('invalid_response');
                }
                if (($payload['data']['version']['value'] ?? null) !== $version) {
                    return $this->failure('version_mismatch');
                }

                return ['ok' => true, 'api_version' => $version,
                    'account' => ['id' => $id, 'name' => $account['name'], 'slug' => $account['slug']]];
            } finally {
                // Keep cleanup inside the sanitizing catch: streams can throw here too.
                $response->toPsrResponse()->getBody()->close();
            }
        } catch (Throwable) {
            // Transport exceptions can contain request headers or remote bodies.
            // Do not report, chain, persist or print the original exception.
            return $this->failure('network_error');
        }
    }

    private function boundedBody(Response $response, int $deadline): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (strlen($body) <= self::MAX_RESPONSE_BYTES) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException;
            }
            $chunk = $stream->read(min(8192, self::MAX_RESPONSE_BYTES + 1 - strlen($body)));
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException;
            }
            if ($chunk === '') {
                if ($stream->eof()) {
                    break;
                }
                throw new \RuntimeException;
            }
            $body .= $chunk;
        }

        return strlen($body) > self::MAX_RESPONSE_BYTES ? null : $body;
    }

    /** @param array<mixed> $errors */
    private function apiError(array $errors): string
    {
        foreach ($errors as $error) {
            $code = is_array($error) ? ($error['extensions']['code'] ?? null) : null;
            if (in_array($code, ['UNAUTHENTICATED', 'UserUnauthorizedException', 'InvalidTokenException'], true)) {
                return 'authentication_failed';
            }
            if (in_array($code, ['FORBIDDEN', 'PERMISSION_DENIED', 'AuthorizationException'], true)) {
                return 'access_denied';
            }
            if (in_array($code, ['RATE_LIMIT_EXCEEDED', 'DAILY_LIMIT_EXCEEDED', 'COMPLEXITY_BUDGET_EXHAUSTED'], true)) {
                return 'rate_limited';
            }
        }

        return 'api_error';
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
