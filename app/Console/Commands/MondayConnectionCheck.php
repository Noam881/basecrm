<?php

namespace App\Console\Commands;

use App\Integrations\Monday\Connection;
use Illuminate\Console\Command;

final class MondayConnectionCheck extends Command
{
    protected $signature = 'crm:monday-check';

    protected $description = 'Read-only monday authentication and account check; never fetches boards or client data';

    public function handle(Connection $connection): int
    {
        $result = $connection->check();
        if (! $result['ok']) {
            $result['message'] = match ($result['error']) {
                'token_missing' => 'MONDAY_API_TOKEN is missing in this execution environment.',
                'configuration_error' => 'Check the private token setting and the pinned monday API version.',
                'authentication_failed' => 'monday rejected authentication. Check the private token without sharing it.',
                'access_denied' => 'The token cannot access the required monday account metadata.',
                'rate_limited' => 'monday has limited API requests. Retry this read-only check later.',
                'version_mismatch' => 'monday did not confirm the pinned API version. Connection is not verified.',
                'response_too_large' => 'The monday connection response exceeded the allowed size.',
                'invalid_response' => 'monday returned an unexpected response. Connection is not verified.',
                'network_error' => 'The HTTPS request failed. Check connectivity, DNS, TLS and timeout availability.',
                default => 'monday returned an API error. Connection is not verified.',
            };
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
