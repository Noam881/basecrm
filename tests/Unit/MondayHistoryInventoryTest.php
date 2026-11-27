<?php

namespace Tests\Unit;

use App\Integrations\Monday\HistoryInventory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayHistoryInventoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2026-10']);
        Http::preventStrayRequests();
    }

    public function test_empty_selections_never_list_all_source_history_or_file_columns(): void
    {
        Http::fake();
        $inventory = app(HistoryInventory::class);
        $this->assertSame([], $inventory->updates('88', '100', [])['items']);
        $this->assertSame([], $inventory->files('88', '100', [], ['documents'])['items']);
        $result = $inventory->files('88', '100', ['11'], []);
        $this->assertTrue($result['ok']);
        $this->assertSame('not_requested', $result['items'][0]['coverage']);
        $this->assertFalse($inventory->updates('88', '100', [11.5])['ok']);
        Http::assertNothingSent();
    }

    public function test_updates_keep_exact_reply_and_asset_relationships_without_fetching_bodies(): void
    {
        Http::fake(function ($request) {
            $this->assertPrivateProjection($request['query']);
            $this->assertStringContainsString('items(ids: $ids, limit: 1, exclude_nonactive: false)', $request['query']);
            $this->assertStringContainsString('updates(limit: 100, page: $page)', $request['query']);
            $this->assertSame(['11'], $request['variables']['ids']);
            $updates = $request['variables']['page'] === 1 ? [
                $this->update('12345678901234567890', '11', [['id' => '41']], [
                    ['id' => '31', 'assets' => [['id' => '41']]],
                ]), $this->update('22'),
            ] : [];

            return $this->response([$this->item('11', ['updates' => $updates])]);
        });
        $result = app(HistoryInventory::class)->updates('88', '100', ['11']);
        $this->assertTrue($result['ok']);
        $item = $result['items'][0];
        $this->assertTrue($item['pagination_complete']);
        $this->assertSame(2, $item['pages_read']);
        $this->assertSame('complete', $item['coverage']);
        $this->assertSame('12345678901234567890', $item['updates'][0]['update_id']);
        $this->assertSame(['updates' => 2, 'replies' => 1, 'asset_relationships' => 2, 'unique_assets' => 1], $item['counts']);
        $this->assertSame(['update_asset', 'reply_asset'], array_column($item['attachments'], 'source_kind'));
        $this->assertSame(['source_kind' => 'reply_asset', 'item_id' => '11', 'update_id' => '12345678901234567890', 'reply_id' => '31', 'asset_id' => '41'], $item['attachments'][1]);
        $this->assertSame('requested_projection_only', $result['coverage_basis']);
        $this->assertContains('item_assets_default_scope', $result['uninspected_locations']);
        Http::assertSentCount(2);
    }

    public function test_exactly_one_hundred_updates_requires_a_terminal_empty_page(): void
    {
        Http::fake(function ($request) {
            return $this->response([$this->item('11', ['updates' => $request['variables']['page'] === 1
                ? array_map(fn ($id) => $this->update((string) $id), range(1001, 1100)) : []])]);
        });
        $result = app(HistoryInventory::class)->updates('88', '100', ['11']);
        $this->assertTrue($result['ok']);
        $this->assertSame(100, $result['items'][0]['counts']['updates']);
        $this->assertSame(2, $result['items'][0]['pages_read']);
        Http::assertSentCount(2);
    }

    public function test_repeated_pages_and_overlapping_update_ids_remain_incomplete(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('11', ['updates' => [$this->update('21')]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('11', ['updates' => [$this->update('21')]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('12', ['updates' => [$this->update('22', '12'), $this->update('23', '12')]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('12', ['updates' => [$this->update('23', '12'), $this->update('24', '12')]])])], 200, ['API-Version' => '2026-10']);
        $result = app(HistoryInventory::class)->updates('88', '100', ['11', '12']);
        $this->assertFalse($result['ok']);
        $this->assertSame(['repeated_update_page', 'duplicate_update_id'], array_column($result['unresolved'], 'reason'));
        $this->assertSame([1, 2], array_column(array_column($result['items'], 'counts'), 'updates'));
        $this->assertSame(['incomplete', 'incomplete'], array_column($result['items'], 'coverage'));
        Http::assertSentCount(4);
    }

    public function test_null_assets_replies_and_null_asset_members_never_become_verified_empty_history(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('11', ['updates' => [
                $this->update('21', '11', null, null),
                $this->update('22', '11', [null], [['id' => '31', 'assets' => null]]),
            ]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('11', ['updates' => []])])], 200, ['API-Version' => '2026-10']);
        $result = app(HistoryInventory::class)->updates('88', '100', ['11']);
        $this->assertFalse($result['ok']);
        $this->assertTrue($result['items'][0]['pagination_complete']);
        $this->assertSame('incomplete', $result['items'][0]['coverage']);
        $this->assertSame(['assets_unavailable', 'replies_unavailable', 'invalid_asset_id', 'assets_unavailable'], array_column($result['unresolved'], 'reason'));
    }

    public function test_wrong_item_board_account_version_and_partial_api_errors_fail_closed(): void
    {
        $wrongBoard = $this->item('11', ['updates' => []]);
        $wrongBoard['board']['id'] = '999';
        $wrongAccount = $this->data([$this->item('11', ['updates' => []])]);
        $wrongAccount['me']['account']['id'] = '99';
        $wrongVersion = $this->data([$this->item('11', ['updates' => []])]);
        $wrongVersion['version']['value'] = '2027-01';
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('99', ['updates' => []])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$wrongBoard])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $wrongAccount], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $wrongVersion], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([]), 'errors' => [['message' => 'raw-private-value']]], 200, ['API-Version' => '2026-10']);
        foreach (['item_missing_or_out_of_scope', 'wrong_board', 'account_or_version_mismatch', 'account_or_version_mismatch', 'api_error'] as $reason) {
            $result = app(HistoryInventory::class)->updates('88', '100', ['11']);
            $this->assertSame($reason, $result['unresolved'][0]['reason']);
            $this->assertFalse($result['items'][0]['pagination_complete']);
            $this->assertStringNotContainsString('raw-private-value', json_encode($result));
        }
    }

    public function test_parent_membership_duplicate_replies_and_null_update_pages_stay_unresolved(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('11', ['updates' => [$this->update('21', '99')]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('12', ['updates' => null])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('13', ['updates' => [
                $this->update('22', '13', [], [['id' => '31', 'assets' => []], ['id' => '31', 'assets' => []]]),
            ]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('13', ['updates' => []])])], 200, ['API-Version' => '2026-10']);
        $result = app(HistoryInventory::class)->updates('88', '100', ['11', '12', '13']);
        $this->assertSame(['invalid_update_parent_or_id', 'updates_unavailable', 'duplicate_reply_id'], array_column($result['unresolved'], 'reason'));
    }

    public function test_cross_item_update_collisions_invalidate_both_observed_parent_associations(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('11', ['updates' => [$this->update('21')]])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('11', ['updates' => []])])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([$this->item('12', ['updates' => [$this->update('21', '12')]])])], 200, ['API-Version' => '2026-10']);
        $result = app(HistoryInventory::class)->updates('88', '100', ['11', '12']);
        $this->assertFalse($result['ok']);
        $this->assertSame(['incomplete', 'incomplete'], array_column($result['items'], 'coverage'));
        $this->assertSame(['11', '12'], array_column($result['unresolved'], 'item_id'));
        $this->assertSame(['update_belongs_to_multiple_items', 'update_belongs_to_multiple_items'], array_column($result['unresolved'], 'reason'));
        Http::assertSentCount(3);
    }

    public function test_cross_item_reply_collisions_are_not_assigned_to_two_parents(): void
    {
        Http::fake(function ($request) {
            $id = $request['variables']['ids'][0];
            $updates = $request['variables']['page'] === 1
                ? [$this->update($id === '11' ? '21' : '22', $id, [], [['id' => '31', 'assets' => []]])] : [];

            return $this->response([$this->item($id, ['updates' => $updates])]);
        });
        $result = app(HistoryInventory::class)->updates('88', '100', ['11', '12']);
        $this->assertFalse($result['ok']);
        $this->assertSame(['incomplete', 'incomplete'], array_column($result['items'], 'coverage'));
        $this->assertSame([1, 0], array_column(array_column($result['items'], 'counts'), 'replies'));
        $this->assertSame(['duplicate_reply_id', 'duplicate_reply_id'], array_column($result['unresolved'], 'reason'));
    }

    public function test_malformed_scalar_items_are_sanitized_and_incomplete(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->data(['raw-private-value'])], 200, ['API-Version' => '2026-10'])]);
        $inventory = app(HistoryInventory::class);
        foreach ([$inventory->updates('88', '100', ['11']), $inventory->files('88', '100', ['11'], ['documents'])] as $result) {
            $this->assertFalse($result['ok']);
            $this->assertSame('incomplete', $result['items'][0]['coverage']);
            $this->assertStringNotContainsString('raw-private-value', json_encode($result));
        }
    }

    public function test_file_unions_keep_their_distinct_ids_and_never_forward_unrequested_content(): void
    {
        Http::fake(function ($request) {
            $this->assertPrivateProjection($request['query']);
            $this->assertSame(['ids' => ['11'], 'columns' => ['documents']], $request['variables']);
            $this->assertStringNotContainsString('assets_source', $request['query']);

            return $this->response([$this->item('11', ['column_values' => [$this->column([
                ['__typename' => 'FileAssetValue', 'asset_id' => '41', 'name' => 'raw-private-value'],
                ['__typename' => 'FileDocValue', 'file_id' => '51', 'object_id' => '52', 'doc' => ['id' => '53'], 'url' => 'raw-private-value'],
                ['__typename' => 'FileLinkValue', 'file_id' => '61', 'kind' => 'google_drive', 'url' => 'raw-private-value'],
                ['__typename' => 'FileAssetInvalidValue', 'asset_id' => '71', 'error' => 'raw-private-value'],
            ])]])]);
        });
        $result = app(HistoryInventory::class)->files('88', '100', ['11'], ['documents']);
        $this->assertFalse($result['ok']);
        $files = $result['items'][0]['files'];
        $this->assertSame(['uploaded_asset', 'workdoc', 'external_link', 'invalid_asset'], array_column($files, 'source_kind'));
        $this->assertSame(['file_id' => '51', 'object_id' => '52', 'doc_id' => '53'], array_intersect_key($files[1], array_flip(['file_id', 'object_id', 'doc_id'])));
        $this->assertSame('invalid_source_asset', $result['unresolved'][0]['reason']);
        $this->assertSame(['file_references' => 4, 'uploaded_assets' => 1, 'workdocs' => 1, 'external_links' => 1, 'invalid_assets' => 1], $result['items'][0]['counts']);
        $this->assertStringNotContainsString('raw-private-value', json_encode($result));
    }

    public function test_missing_file_columns_null_files_unknown_types_and_duplicates_are_explicit(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('11', ['column_values' => []]),
                $this->item('12', ['column_values' => [$this->column(null)]]),
                $this->item('13', ['column_values' => [$this->column([
                    ['__typename' => 'UnknownFileKind', 'name' => 'raw-private-value'],
                    ['__typename' => 'FileAssetValue', 'asset_id' => '41'],
                    ['__typename' => 'FileAssetValue', 'asset_id' => '41'],
                ])]]),
            ])], 200, ['API-Version' => '2026-10']);
        $result = app(HistoryInventory::class)->files('88', '100', ['11', '12', '13', '14'], ['documents']);
        $this->assertFalse($result['ok']);
        $this->assertEqualsCanonicalizing(['file_column_missing', 'file_column_unavailable', 'unsupported_file_kind', 'duplicate_file_reference', 'item_missing_or_inaccessible'], array_column($result['unresolved'], 'reason'));
        $this->assertSame(['incomplete', 'incomplete', 'incomplete', 'incomplete'], array_column($result['items'], 'coverage'));
        $this->assertStringNotContainsString('raw-private-value', json_encode($result));
    }

    public function test_file_identifiers_stay_opaque_and_unknown_link_kinds_are_not_printed(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->data([$this->item('11', ['column_values' => [$this->column([
            ['__typename' => 'FileLinkValue', 'file_id' => '8a0dff4e-a788-4cf3-ad52-5fdde1ba1cce', 'kind' => 'link'],
            ['__typename' => 'FileLinkValue', 'file_id' => 'opaque-file-002', 'kind' => 'RAW_PRIVATE_VALUE'],
        ])]])])], 200, ['API-Version' => '2026-10'])]);
        $result = app(HistoryInventory::class)->files('88', '100', ['11'], ['documents']);
        $this->assertFalse($result['ok']);
        $this->assertSame('8a0dff4e-a788-4cf3-ad52-5fdde1ba1cce', $result['items'][0]['files'][0]['file_id']);
        $this->assertSame('opaque-file-002', $result['items'][0]['files'][1]['file_id']);
        $this->assertArrayNotHasKey('kind', $result['items'][0]['files'][1]);
        $this->assertStringNotContainsString('RAW_PRIVATE_VALUE', json_encode($result));
        $this->assertSame('external_link_kind_unavailable', $result['unresolved'][0]['reason']);
    }

    public function test_file_batches_are_bounded_and_reject_wrong_or_duplicate_item_membership(): void
    {
        $sizes = [];
        Http::fake(function ($request) use (&$sizes) {
            $ids = $request['variables']['ids'];
            $sizes[] = count($ids);

            return $this->response(array_map(fn ($id) => $this->item($id, ['column_values' => [$this->column([])]]), $ids));
        });
        $result = app(HistoryInventory::class)->files('88', '100', array_map('strval', range(1, 101)), ['documents']);
        $this->assertTrue($result['ok']);
        $this->assertSame([100, 1], $sizes);
        $this->assertSame(['complete'], array_values(array_unique(array_column($result['items'], 'coverage'))));
        Http::assertSentCount(2);
    }

    public function test_page_limits_and_rate_failures_defer_without_claiming_empty_success(): void
    {
        config(['services.monday.history_max_pages' => 1]);
        Http::fakeSequence()
            ->push(['data' => $this->data([$this->item('11', ['updates' => [$this->update('21', '11', null)]])])], 200, ['API-Version' => '2026-10'])
            ->push('raw-private-value', 429);
        $result = app(HistoryInventory::class)->updates('88', '100', ['11', '12', '13']);
        $this->assertEqualsCanonicalizing(['assets_unavailable', 'history_page_limit_reached', 'rate_limited', 'deferred_after_rate_limit'], array_column($result['unresolved'], 'reason'));
        $this->assertSame(['incomplete', 'incomplete', 'incomplete'], array_column($result['items'], 'coverage'));
        Http::assertSentCount(2);
    }

    private function assertPrivateProjection(string $query): void
    {
        $projection = str_replace('version { value }', '', $query);
        $this->assertDoesNotMatchRegularExpression('/\b(value|text|text_body|body|name|url|public_url|creator|creator_id|error|file_size)\b/', $projection);
        $this->assertStringContainsString('items(ids: $ids', $query);
    }

    private function data(array $items): array
    {
        return ['me' => ['account' => ['id' => '88']], 'version' => ['value' => '2026-10'], 'items' => $items];
    }

    private function item(string $id, array $attributes): array
    {
        return ['id' => $id, 'board' => ['id' => '100'], ...$attributes];
    }

    private function update(string $id, string $item = '11', ?array $assets = [], ?array $replies = []): array
    {
        return ['id' => $id, 'item_id' => $item, 'assets' => $assets, 'replies' => $replies];
    }

    private function column(?array $files): array
    {
        return ['id' => 'documents', '__typename' => 'FileValue', 'files' => $files];
    }

    private function response(array $items)
    {
        return Http::response(['data' => $this->data($items)], 200, ['API-Version' => '2026-10']);
    }
}
