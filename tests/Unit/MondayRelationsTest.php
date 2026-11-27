<?php

namespace Tests\Unit;

use App\Integrations\Monday\Relations;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayRelationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2026-10']);
        Http::preventStrayRequests();
    }

    public function test_it_queries_only_filtered_relation_ids_and_preserves_multiple_targets(): void
    {
        Http::fake(function ($request) {
            $query = $request['query'];
            $projection = str_replace('version { value }', '', $query);
            $this->assertDoesNotMatchRegularExpression('/\b(value|text|name|amount|password|body|updates|files|assets)\b/', $projection);
            $this->assertStringContainsString('... on BoardRelationValue', $query);
            $this->assertStringContainsString('exclude_nonactive: false', $query);
            $this->assertSame(['ids' => ['11'], 'columns' => ['relation_a', 'relation_b']], $request['variables']);

            return Http::response(['data' => $this->data([$this->item('11', [
                $this->column('relation_a', ['21', '22'], ['21' => '200', '22' => '300']),
                $this->column('relation_b', ['23'], ['23' => '200']),
            ])])], 200, ['API-Version' => '2026-10']);
        });
        $result = app(Relations::class)->read('88', '100', ['11'], ['relation_a', 'relation_b']);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['unresolved']);
        $this->assertSame([
            ['from_item_id' => '11', 'column_id' => 'relation_a', 'to_item_id' => '21', 'to_board_id' => '200'],
            ['from_item_id' => '11', 'column_id' => 'relation_a', 'to_item_id' => '22', 'to_board_id' => '300'],
            ['from_item_id' => '11', 'column_id' => 'relation_b', 'to_item_id' => '23', 'to_board_id' => '200'],
        ], $result['edges']);
    }

    public function test_scoped_ids_are_batched_at_one_hundred_and_empty_relations_are_explicit(): void
    {
        $sizes = [];
        Http::fake(function ($request) use (&$sizes) {
            $ids = $request['variables']['ids'];
            $sizes[] = count($ids);

            return Http::response(['data' => $this->data(array_map(fn ($id) => $this->item($id, [$this->column('relation_a')]), $ids))], 200, ['API-Version' => '2026-10']);
        });
        $ids = array_map('strval', range(1, 101));
        $result = app(Relations::class)->read('88', '100', $ids, ['relation_a']);
        $this->assertTrue($result['ok']);
        $this->assertSame([100, 1], $sizes);
        $this->assertSame([], $result['edges']);
        Http::assertSentCount(2);
    }

    public function test_an_empty_anchored_selection_never_falls_back_to_listing_source_items(): void
    {
        Http::fake();
        $this->assertSame(['ok' => true, 'edges' => [], 'unresolved' => []],
            app(Relations::class)->read('88', '100', [], ['relation_a']));
        Http::assertNothingSent();
    }

    public function test_missing_item_column_and_wrong_column_types_are_unresolved(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->data([
            $this->item('11', []),
            $this->item('12', [['id' => 'relation_a', '__typename' => 'TextValue']]),
            $this->item('13', null),
        ])], 200, ['API-Version' => '2026-10'])]);
        $result = app(Relations::class)->read('88', '100', ['11', '12', '13', '14'], ['relation_a']);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['edges']);
        $this->assertSame(['relation_column_missing', 'relation_type_mismatch', 'relation_columns_unavailable', 'item_missing_or_inaccessible'], array_column($result['unresolved'], 'reason'));
    }

    public function test_unavailable_linked_items_preserve_known_ids_without_guessing_their_board(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->data([$this->item('11', [
            $this->column('relation_a', ['21', '22'], ['21' => '200', '23' => '300']),
        ])])], 200, ['API-Version' => '2026-10'])]);
        $result = app(Relations::class)->read('88', '100', ['11'], ['relation_a']);
        $this->assertFalse($result['ok']);
        $this->assertSame(['21', '22', '23'], array_column($result['edges'], 'to_item_id'));
        $this->assertArrayNotHasKey('to_board_id', $result['edges'][1]);
        $this->assertSame(['linked_item_inaccessible', 'linked_item_ids_mismatch'], array_column($result['unresolved'], 'reason'));
    }

    public function test_partial_null_relation_fields_are_not_converted_to_no_links(): void
    {
        Http::fake(['*' => Http::response(['data' => $this->data([$this->item('11', [[
            'id' => 'relation_a', '__typename' => 'BoardRelationValue', 'linked_item_ids' => ['21'], 'linked_items' => null,
        ]])])], 200, ['API-Version' => '2026-10'])]);
        $result = app(Relations::class)->read('88', '100', ['11'], ['relation_a']);
        $this->assertFalse($result['ok']);
        $this->assertSame([['from_item_id' => '11', 'column_id' => 'relation_a', 'to_item_id' => '21']], $result['edges']);
        $this->assertContains('linked_items_unavailable', array_column($result['unresolved'], 'reason'));
    }

    public function test_wrong_board_account_version_and_partial_api_errors_never_return_edges(): void
    {
        $wrongBoard = $this->item('11', [$this->column('relation_a', ['21'], ['21' => '200'])]);
        $wrongBoard['board']['id'] = '999';
        $wrongAccount = $this->data([]);
        $wrongAccount['me']['account']['id'] = '99';
        $wrongVersion = $this->data([]);
        $wrongVersion['version']['value'] = '2027-01';
        Http::fakeSequence()
            ->push(['data' => $this->data([$wrongBoard])], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $wrongAccount], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $wrongVersion], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->data([]), 'errors' => [['message' => 'raw-private-value']]], 200, ['API-Version' => '2026-10']);
        foreach (['wrong_board', 'account_or_version_mismatch', 'account_or_version_mismatch', 'api_error'] as $reason) {
            $result = app(Relations::class)->read('88', '100', ['11'], ['relation_a']);
            $this->assertSame([], $result['edges']);
            $this->assertSame($reason, $result['unresolved'][0]['reason']);
            $this->assertStringNotContainsString('raw-private-value', json_encode($result));
        }
    }

    public function test_float_ids_invalid_columns_and_unscoped_responses_are_rejected(): void
    {
        Http::fakeSequence()->push(['data' => $this->data([$this->item('99', [$this->column('relation_a')])])], 200, ['API-Version' => '2026-10']);
        foreach ([[11.5], ['11.5']] as $ids) {
            $this->assertSame('invalid_selection', app(Relations::class)->read('88', '100', $ids, ['relation_a'])['unresolved'][0]['reason']);
        }
        $this->assertSame('invalid_selection', app(Relations::class)->read('88', '100', ['11'], ["bad\x00column"])['unresolved'][0]['reason']);
        $result = app(Relations::class)->read('88', '100', ['11'], ['relation_a']);
        $this->assertSame([], $result['edges']);
        $this->assertSame(['unexpected_or_invalid_item', 'item_missing_or_inaccessible'], array_column($result['unresolved'], 'reason'));
        Http::assertSentCount(1);
    }

    public function test_rate_limits_defer_remaining_batches_without_a_retry_burst(): void
    {
        Http::fakeSequence()->push('raw-private-value', 429);
        $result = app(Relations::class)->read('88', '100', array_map('strval', range(1, 101)), ['relation_a']);
        $this->assertFalse($result['ok']);
        $this->assertCount(101, $result['unresolved']);
        $this->assertSame('deferred_after_rate_limit', $result['unresolved'][100]['reason']);
        Http::assertSentCount(1);
    }

    private function data(array $items): array
    {
        return ['me' => ['account' => ['id' => '88']], 'version' => ['value' => '2026-10'], 'items' => $items];
    }

    private function item(string $id, ?array $columns): array
    {
        return ['id' => $id, 'board' => ['id' => '100'], 'column_values' => $columns];
    }

    private function column(string $id, array $ids = [], array $boards = []): array
    {
        return ['id' => $id, '__typename' => 'BoardRelationValue', 'linked_item_ids' => $ids,
            'linked_items' => array_map(fn ($target, $board) => ['id' => (string) $target, 'board' => ['id' => $board]], array_keys($boards), array_values($boards))];
    }
}
