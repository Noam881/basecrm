<?php

namespace App\Console\Commands;

use App\Integrations\Monday\Inventory;
use App\Integrations\Monday\ReadOnlyClient;
use App\Integrations\Monday\Relations;
use App\Integrations\Monday\Structure;
use Illuminate\Console\Command;
use Throwable;

final class MondayInventory extends Command
{
    protected $signature = 'crm:monday-inventory {--account=} {--board=*} {--schema-only} {--references-only} {--structure-only} {--relations-plan=} {--report=}';

    protected $description = 'Read-only selected-board definitions; excludes item values, secrets and document bodies';

    public function handle(Inventory $inventory, Structure $structure, Relations $relations): int
    {
        try {
            $account = $this->option('account');
            $report = $this->option('report');
            if (! is_string($account) || ! preg_match('/^[1-9][0-9]{0,63}$/D', $account)
                || ! is_string($report) || ! preg_match('/^[a-z][a-z0-9_-]{0,80}$/D', $report)
                || count(array_filter([$this->option('schema-only'), $this->option('references-only'), $this->option('structure-only'),
                    $this->option('relations-plan') !== null])) > 1) {
                return $this->failure('invalid_selection_or_report_name');
            }
            if ($this->option('relations-plan') !== null) {
                $result = $this->relations($relations, $account);
            } elseif ($this->option('structure-only')) {
                $result = $structure->enumerate($account, $this->option('board'));
            } elseif ($this->option('references-only')) {
                $result = $inventory->references($account, $this->option('board'));
            } else {
                $schema = $inventory->schema($account);
                if (! $schema['ok']) {
                    return $this->failure($schema['error']);
                }
                $result = $this->option('schema-only') ? ['ok' => true, 'contract' => $schema['contract']]
                    : $inventory->boards($account, $this->option('board'), $schema['contract']);
            }
            $result = ['account_id' => $account, 'api_version' => ReadOnlyClient::API_VERSION,
                'observed_at_utc' => gmdate(DATE_ATOM), ...$result];
            $path = $this->save($report, $result);
            $this->line(json_encode(['ok' => $result['ok'], 'report' => $path,
                'boards_returned' => count($result['boards'] ?? []), 'unresolved_count' => count($result['unresolved'] ?? []),
                'unresolved' => array_slice($result['unresolved'] ?? [], 0, 10),
                'error' => $result['error'] ?? null], JSON_THROW_ON_ERROR));

            return $result['ok'] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable) {
            return $this->failure('inventory_or_private_report_failed');
        }
    }

    private function relations(Relations $reader, string $account): array
    {
        $name = $this->option('relations-plan');
        if (! is_string($name) || ! preg_match('/^[a-z][a-z0-9_-]{0,80}$/D', $name)) {
            throw new \RuntimeException;
        }
        $path = storage_path('app/private/monday-migration/'.$name.'.json');
        if (is_link($path) || ! is_file($path) || filesize($path) > 5242880 || (fileperms($path) & 0077) !== 0) {
            throw new \RuntimeException;
        }
        $plan = json_decode(file_get_contents($path), true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        if (! is_array($plan) || ($plan['account_id'] ?? null) !== $account
            || ! is_array($plan['requests'] ?? null) || ! array_is_list($plan['requests']) || count($plan['requests']) > 100) {
            throw new \RuntimeException;
        }
        $edges = [];
        $pending = $plan['unresolved'] ?? [];
        if (! is_array($pending) || ! array_is_list($pending)) {
            throw new \RuntimeException;
        }
        $unresolved = [];
        foreach ($pending as $entry) {
            if (! is_array($entry) || ! is_string($entry['board_id'] ?? null)
                || ! preg_match('/^[1-9][0-9]{0,63}$/D', $entry['board_id'])) {
                throw new \RuntimeException;
            }
            $unresolved[] = ['board_id' => $entry['board_id'], 'reason' => 'source_plan_unresolved'];
        }
        foreach ($plan['requests'] as $request) {
            if (! is_array($request) || ! is_string($request['board_id'] ?? null)
                || ! is_array($request['item_ids'] ?? null) || ! is_array($request['column_ids'] ?? null)) {
                throw new \RuntimeException;
            }
            $result = $reader->read($account, $request['board_id'], $request['item_ids'], $request['column_ids']);
            foreach ($result['edges'] as $edge) {
                $edges[] = ['from_board_id' => $request['board_id'], ...$edge];
            }
            $unresolved = array_merge($unresolved, $result['unresolved']);
        }

        return ['ok' => $unresolved === [], 'edges' => $edges, 'unresolved' => $unresolved,
            'scope' => 'relationship_identifiers_only', 'credential_values_read' => false];
    }

    private function save(string $name, array $result): string
    {
        $directory = storage_path('app/private/monday-migration');
        if (is_link($directory) || (! is_dir($directory) && ! mkdir($directory, 0700, true))) {
            throw new \RuntimeException;
        }
        $resolved = realpath($directory);
        $public = realpath(public_path());
        if ($resolved === false || $public === false || str_starts_with($resolved.'/', $public.'/')
            || (fileperms($directory) & 0077) !== 0) {
            throw new \RuntimeException;
        }
        $path = $directory.'/'.$name.'.json';
        $body = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        $handle = fopen($path, 'x');
        if ($handle === false) {
            throw new \RuntimeException;
        }
        try {
            if (! chmod($path, 0600) || fwrite($handle, $body) !== strlen($body) || ! fflush($handle)) {
                throw new \RuntimeException;
            }
        } finally {
            fclose($handle);
        }

        return $path;
    }

    private function failure(string $error): int
    {
        $this->line(json_encode(['ok' => false, 'error' => $error], JSON_THROW_ON_ERROR));

        return self::FAILURE;
    }
}
