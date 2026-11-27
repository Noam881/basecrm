<?php

namespace Awove\Vault\Imports;

use App\Integrations\Monday\AssetDownloader;
use App\Integrations\Monday\AssetLocation;
use App\Integrations\Monday\ReadOnlyClient;
use Awove\Vault\Files;
use Illuminate\Http\Request;

/** File location is obtained freshly from one exact uploaded-file occurrence, never an input URL. */
class MondayAsset
{
    private const QUERY = 'query BaseCrmVaultAsset($ids:[ID!]!,$columns:[String!]!) { me { account { id } } version { value } '
        .'items(ids:$ids,limit:1,exclude_nonactive:false) { id board { id } column_values(ids:$columns,capabilities:[CALCULATED,VISIBILITY]) { id type value text '
        .'... on FileValue { files { __typename ... on FileAssetValue { asset_id name created_at creator_id creator { id name } '
        .'asset { id name file_extension file_size created_at public_url } } } } } } }';

    public function fetch(Request $request, MondayPlan $plan, array $node, array $occurrence, array $expectedColumn, \Closure $authorize): array
    {
        $authorize();
        $data = app(MondaySource::class)->query($request, self::QUERY, ['ids' => [$node['id']], 'columns' => [$occurrence['column_id']]]);
        $authorize();
        [$location, $source] = $this->location($plan, $node, $occurrence, $expectedColumn, $data);
        $window = ImportWindow::require($request);
        abort_unless($window->expiresAt->greaterThan(now()->addSeconds(25)), 423);
        $authorize();
        $plain = app(AssetDownloader::class)->download($location, Files::MAX_BYTES);
        try {
            $authorize();
            ImportWindow::require($request);
            $info = app(Files::class)->information($source['original_name'], $plain);
            abort_unless($info['size_bytes'] === $location->size() && $info['extension'] === $location->extension(), 422);

            return ['plaintext' => $plain, 'source' => $source];
        } catch (\Throwable $error) {
            sodium_memzero($plain);
            throw $error;
        }
    }

    public function location(MondayPlan $plan, array $node, array $occurrence, #[\SensitiveParameter] array $expectedColumn, #[\SensitiveParameter] array $data): array
    {
        abort_unless(($data['me']['account']['id'] ?? null) === $plan->data['account_id'] && ($data['version']['value'] ?? null) === ReadOnlyClient::API_VERSION
            && is_array($data['items'] ?? null) && array_is_list($data['items']) && count($data['items']) === 1, 503);
        $item = $data['items'][0];
        abort_unless(($item['id'] ?? null) === $node['id'] && ($item['board']['id'] ?? null) === $node['board_id']
            && is_array($item['column_values'] ?? null) && array_is_list($item['column_values']) && count($item['column_values']) === 1, 503);
        $column = $item['column_values'][0];
        abort_unless(($column['id'] ?? null) === $occurrence['column_id'] && ($column['type'] ?? null) === 'file' && ($expectedColumn['type'] ?? null) === 'file'
            && array_key_exists('value', $column) && array_key_exists('text', $column) && $column['value'] === $expectedColumn['value'] && $column['text'] === $expectedColumn['text']
            && is_array($column['files'] ?? null) && array_is_list($column['files']), 503);
        $file = $column['files'][$occurrence['index']] ?? null;
        abort_unless(is_array($file) && ($file['__typename'] ?? null) === 'FileAssetValue' && ($file['asset_id'] ?? null) === $occurrence['id']
            && is_string($file['name'] ?? null) && is_string($file['created_at'] ?? null) && array_key_exists('creator', $file)
            && ($file['creator'] === null || is_array($file['creator']) && is_string($file['creator']['id'] ?? null) && is_string($file['creator']['name'] ?? null))
            && array_key_exists('creator_id', $file) && ($file['creator_id'] === null || is_string($file['creator_id'])), 503);
        abort_unless($file['creator'] === null || $file['creator_id'] === null || $file['creator']['id'] === $file['creator_id'], 503);
        $asset = $file['asset'] ?? null;
        abort_unless(is_array($asset) && ($asset['id'] ?? null) === $occurrence['id'] && is_string($asset['name'] ?? null)
            && is_string($asset['file_extension'] ?? null) && is_int($asset['file_size'] ?? null) && $asset['file_size'] > 0 && $asset['file_size'] <= Files::MAX_BYTES
            && Files::supports(strtolower($asset['file_extension'])) && strtolower(pathinfo($asset['name'], PATHINFO_EXTENSION)) === strtolower($asset['file_extension']) && array_key_exists('created_at', $asset)
            && ($asset['created_at'] === null || is_string($asset['created_at'])) && is_string($asset['public_url'] ?? null), 422);
        $source = ['provider' => 'monday', 'asset_id' => $occurrence['id'], 'board_id' => $node['board_id'], 'item_id' => $node['id'],
            'column_id' => $occurrence['column_id'], 'source_index' => $occurrence['index'], 'original_name' => $asset['name'], 'column_name' => $file['name'],
            'file_extension' => $asset['file_extension'], 'created_at' => $asset['created_at'], 'column_created_at' => $file['created_at'],
            'creator' => $file['creator'], 'creator_id' => $file['creator_id']];
        abort_unless(strlen(json_encode($source, JSON_THROW_ON_ERROR)) <= 32768, 422);
        $location = AssetLocation::fromSource($asset['id'], $asset['name'], strtolower($asset['file_extension']), $asset['file_size'], $asset['public_url']);

        return [$location, $source];
    }
}
