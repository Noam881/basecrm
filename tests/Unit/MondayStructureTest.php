<?php

namespace Tests\Unit;

use App\Integrations\Monday\Structure;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayStructureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2026-10']);
        Http::preventStrayRequests();
    }

    public function test_it_enumerates_all_pages_with_only_ids_states_and_actual_subitem_parents(): void
    {
        Http::fake(function ($request) {
            $this->assertDoesNotMatchRegularExpression('/\b(name|column_values|updates|body|files|assets|password)\b/', $request['query']);
            $this->assertStringContainsString('limit: 100', $request['query']);
            if (isset($request['variables']['ids'])) {
                $this->assertSame(['ids' => ['10']], $request['variables']);
                $this->assertStringContainsString('state: all', $request['query']);

                return $this->response($this->first('10', [$this->item('12345678901234567890', [
                    $this->subitem('30', '90', '12345678901234567890'), $this->subitem('31', '90', '30', 'archived'),
                ])], 'opaque-cursor'));
            }
            $this->assertSame(['cursor' => 'opaque-cursor'], $request['variables']);
            $this->assertStringContainsString('next_items_page', $request['query']);

            return $this->response($this->next([$this->item('20')], null));
        });
        $result = app(Structure::class)->enumerate('88', ['10']);
        $this->assertTrue($result['ok']);
        $this->assertSame([], $result['unresolved']);
        $board = $result['boards'][0];
        $this->assertSame(['12345678901234567890', '20'], $board['parent_item_ids']);
        $this->assertSame(2, $board['active_parent_count']);
        $this->assertSame(2, $board['unique_subitem_count']);
        $this->assertSame(['id' => '31', 'board_id' => '90', 'parent_item_id' => '30', 'state' => 'archived'], $board['subitems'][1]);
        $this->assertSame('active_only', $board['coverage']);
        $this->assertSame('unresolved', $board['archived_coverage']);
        Http::assertSentCount(2);
    }

    public function test_repeated_cursor_retains_partial_counts_and_other_boards_continue(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->first('10', [$this->item('11')], 'same')], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->next([$this->item('12')], 'same')], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->first('20', [], null)], 200, ['API-Version' => '2026-10']);
        $result = app(Structure::class)->enumerate('88', ['10', '20']);
        $this->assertFalse($result['ok']);
        $this->assertSame('20', $result['boards'][0]['board_id']);
        $this->assertSame(0, $result['boards'][0]['active_parent_count']);
        $this->assertSame('repeated_cursor', $result['unresolved'][0]['reason']);
        $this->assertSame(['11'], $result['unresolved'][0]['observed_parent_item_ids']);
        $this->assertSame(1, $result['unresolved'][0]['pages_completed']);
        Http::assertSentCount(3);
    }

    public function test_duplicate_parent_or_subitem_ids_are_not_silently_deduplicated(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->first('10', [$this->item('11')], 'next')], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->next([$this->item('11')], null)], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->first('20', [$this->item('21', [$this->subitem('22', '90', '21'), $this->subitem('22', '90', '21')])], null)], 200, ['API-Version' => '2026-10']);
        $result = app(Structure::class)->enumerate('88', ['10', '20']);
        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['boards']);
        $this->assertSame(['duplicate_item_id', 'duplicate_item_id'], array_column($result['unresolved'], 'reason'));
        $this->assertSame(['11'], $result['unresolved'][0]['observed_parent_item_ids']);
    }

    public function test_missing_boards_partial_null_and_api_errors_remain_explicit(): void
    {
        Http::fakeSequence()
            ->push(['data' => [...$this->identity(), 'boards' => []]], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->first('20', [['id' => '21', 'state' => 'active', 'subitems' => null]], null)], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->first('30', [], null), 'errors' => [['message' => 'raw-private-value']]], 200, ['API-Version' => '2026-10']);
        $result = app(Structure::class)->enumerate('88', ['10', '20', '30']);
        $this->assertSame(['inaccessible_or_not_found', 'invalid_item_structure', 'api_error'], array_column($result['unresolved'], 'reason'));
        $this->assertStringNotContainsString('raw-private-value', json_encode($result));
        $this->assertSame([], $result['boards']);
        Http::assertSentCount(3);
    }

    public function test_page_count_and_size_boundaries_do_not_claim_complete_coverage(): void
    {
        config(['services.monday.structure_max_pages' => 1]);
        Http::fakeSequence()
            ->push(['data' => $this->first('10', [$this->item('11')], 'another')], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->first('20', array_fill(0, 101, $this->item('21')), null)], 200, ['API-Version' => '2026-10']);
        $result = app(Structure::class)->enumerate('88', ['10', '20']);
        $this->assertSame(['page_limit_reached', 'invalid_page'], array_column($result['unresolved'], 'reason'));
        $this->assertSame(['11'], $result['unresolved'][0]['observed_parent_item_ids']);
        Http::assertSentCount(2);
    }

    public function test_account_version_and_subitem_relationship_fields_are_checked(): void
    {
        $wrongAccount = $this->first('10', [], null);
        $wrongAccount['me']['account']['id'] = '99';
        $wrongVersion = $this->first('20', [], null);
        $wrongVersion['version']['value'] = '2027-01';
        Http::fakeSequence()
            ->push(['data' => $wrongAccount], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $wrongVersion], 200, ['API-Version' => '2026-10'])
            ->push(['data' => $this->first('30', [$this->item('31', [['id' => '32', 'state' => 'active', 'board' => ['id' => '90'], 'parent_item' => null]])], null)], 200, ['API-Version' => '2026-10']);
        $result = app(Structure::class)->enumerate('88', ['10', '20', '30']);
        $this->assertSame(['account_or_version_mismatch', 'account_or_version_mismatch', 'invalid_subitem_structure'], array_column($result['unresolved'], 'reason'));
    }

    public function test_invalid_selection_or_page_limits_make_no_request(): void
    {
        Http::fake();
        foreach ([[], ['not-an-id'], [10]] as $ids) {
            $this->assertFalse(app(Structure::class)->enumerate('88', $ids)['ok']);
        }
        foreach ([0, 10001, '100'] as $maximum) {
            config(['services.monday.structure_max_pages' => $maximum]);
            $this->assertFalse(app(Structure::class)->enumerate('88', ['10'])['ok']);
        }
        Http::assertNothingSent();
    }

    private function identity(): array
    {
        return ['me' => ['account' => ['id' => '88']], 'version' => ['value' => '2026-10']];
    }

    private function first(string $id, array $items, ?string $cursor): array
    {
        return [...$this->identity(), 'boards' => [['id' => $id, 'items_page' => ['cursor' => $cursor, 'items' => $items]]]];
    }

    private function next(array $items, ?string $cursor): array
    {
        return [...$this->identity(), 'next_items_page' => ['cursor' => $cursor, 'items' => $items]];
    }

    private function item(string $id, array $subitems = []): array
    {
        return ['id' => $id, 'state' => 'active', 'subitems' => $subitems];
    }

    private function subitem(string $id, string $board, string $parent, string $state = 'active'): array
    {
        return ['id' => $id, 'state' => $state, 'board' => ['id' => $board], 'parent_item' => ['id' => $parent]];
    }

    private function response(array $data)
    {
        return Http::response(['data' => $data], 200, ['API-Version' => '2026-10']);
    }
}
