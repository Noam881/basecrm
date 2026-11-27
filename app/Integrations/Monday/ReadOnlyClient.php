<?php

namespace App\Integrations\Monday;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;
use Throwable;

/** Server-only transport: explicitly selects the first named query operation. */
final class ReadOnlyClient
{
    public const API_VERSION = '2026-10';

    private const MAX_RESPONSE_BYTES = 1048576;

    /** @return array{ok: true, data: array<string, mixed>}|array{ok: false, error: string} */
    public function query(string $query, array $variables = [], int $maxResponseBytes = 65536): array
    {
        try {
            if (strlen($query) > 65536 || ! preg_match('/\A\s*query\s+([_A-Za-z][_0-9A-Za-z]*)\s*(?=[({])/', $query, $operation)
                || $maxResponseBytes < 1 || $maxResponseBytes > self::MAX_RESPONSE_BYTES
                || ($variables !== [] && array_is_list($variables))) {
                return $this->failure('configuration_error');
            }
            foreach (array_keys($variables) as $name) {
                if (! is_string($name) || ! preg_match('/^[_A-Za-z][_0-9A-Za-z]*$/D', $name)) {
                    return $this->failure('configuration_error');
                }
            }
            $token = config('services.monday.token');
            $version = config('services.monday.api_version');
            if (! is_string($token) || trim($token) === '') {
                return $this->failure('token_missing');
            }
            if ($token !== trim($token) || preg_match('/[\r\n\x00]/', $token) || $version !== self::API_VERSION) {
                return $this->failure('configuration_error');
            }

            // Selecting the validated query name prevents another operation from executing.
            $body = ['query' => $query, 'operationName' => $operation[1]];
            if ($variables !== []) {
                $body['variables'] = $variables;
            }
            try {
                if (strlen(json_encode($body, JSON_THROW_ON_ERROR)) > self::MAX_RESPONSE_BYTES) {
                    return $this->failure('configuration_error');
                }
            } catch (JsonException) {
                return $this->failure('configuration_error');
            }

            $deadline = hrtime(true) + 20_000_000_000;
            $response = Http::acceptJson()->asJson()
                ->withHeaders(['Authorization' => $token, 'API-Version' => $version])
                ->connectTimeout(5)->timeout(20)
                ->withOptions(['allow_redirects' => false, 'verify' => true, 'stream' => true, 'read_timeout' => 2])
                ->post('https://api.monday.com/v2', $body);

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

                $raw = $this->boundedBody($response, $deadline, $maxResponseBytes);
                if ($raw === null) {
                    return $this->failure('response_too_large');
                }
                try {
                    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
                } catch (JsonException) {
                    return $this->failure('invalid_response');
                }
                if (! is_array($payload) || array_is_list($payload)) {
                    return $this->failure('invalid_response');
                }
                // Partial GraphQL data must not become a successful extraction.
                if (array_key_exists('errors', $payload)) {
                    if (! is_array($payload['errors'])) {
                        return $this->failure('invalid_response');
                    }
                    if ($payload['errors'] !== []) {
                        return $this->failure($this->apiError($payload['errors']));
                    }
                }
                if (! isset($payload['data']) || ! is_array($payload['data']) || array_is_list($payload['data'])) {
                    return $this->failure('invalid_response');
                }

                return ['ok' => true, 'data' => $payload['data']];
            } finally {
                // Cleanup is inside the outer catch because stream close can throw.
                $response->toPsrResponse()->getBody()->close();
            }
        } catch (Throwable) {
            // Raw exceptions can contain tokens, request headers or sensitive responses.
            return $this->failure('network_error');
        }
    }

    private function boundedBody(Response $response, int $deadline, int $maximum): ?string
    {
        $stream = $response->toPsrResponse()->getBody();
        $body = '';
        while (strlen($body) <= $maximum) {
            if (hrtime(true) >= $deadline) {
                throw new \RuntimeException;
            }
            $chunk = $stream->read(min(8192, $maximum + 1 - strlen($body)));
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

        return strlen($body) > $maximum ? null : $body;
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

    /** @return array{ok: false, error: string} */
    private function failure(string $category): array
    {
        return ['ok' => false, 'error' => $category];
    }
}
