<?php

namespace Tests\Unit;

use App\Integrations\Monday\Inventory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayInventoryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2026-10']);
        Http::preventStrayRequests();
    }

    public function test_selected_board_definitions_exclude_item_values_and_preserve_missing_boards_as_unresolved(): void
    {
        Http::fakeSequence()->push($this->payload([$this->board('101')]), 200, $this->headers())
            ->push($this->payload([]), 200, $this->headers());
        $result = app(Inventory::class)->boards('88', ['101', '102'], $this->contract());

        $this->assertFalse($result['ok']);
        $this->assertSame('101', $result['boards'][0]['id']);
        $this->assertCount(1, $result['boards']);
        $this->assertSame([['board_id' => '102', 'reason' => 'inaccessible_or_not_found']], $result['unresolved']);
        $this->assertFalse($result['item_values_read']);
        $this->assertSame('unresolved', $result['archived_items_coverage']);
        Http::assertSentCount(2);
        foreach (Http::recorded() as [$request]) {
            $this->assertSame('BaseCrmBoardInventory', $request['operationName']);
            $this->assertContains($request['variables']['ids'], [['101'], ['102']]);
            $this->assertDoesNotMatchRegularExpression('/column_values|items_page|assets|public_url|updates/', $request['query']);
        }
    }

    public function test_a_partial_api_failure_does_not_discard_an_independent_success_or_leak_raw_data(): void
    {
        Http::fakeSequence()
            ->push(['data' => $this->payload([$this->board('101')])['data'], 'errors' => [['message' => 'raw-private-marker']]], 200, $this->headers())
            ->push($this->payload([$this->board('102')]), 200, $this->headers());
        $result = app(Inventory::class)->boards('88', ['101', '102'], $this->contract());

        $this->assertFalse($result['ok']);
        $this->assertSame([['board_id' => '101', 'reason' => 'api_error']], $result['unresolved']);
        $this->assertSame('102', $result['boards'][0]['id']);
        $this->assertStringNotContainsString('raw-private-marker', json_encode($result));
    }

    public function test_wrong_account_or_returned_board_cannot_be_reported_as_the_selected_source(): void
    {
        $wrongAccount = $this->payload([$this->board('101')]);
        $wrongAccount['data']['me']['account']['id'] = '99';
        Http::fakeSequence()->push($wrongAccount, 200, $this->headers())
            ->push($this->payload([$this->board('999')]), 200, $this->headers());
        $result = app(Inventory::class)->boards('88', ['101', '102'], $this->contract());

        $this->assertSame([], $result['boards']);
        $this->assertSame('account_or_version_mismatch', $result['unresolved'][0]['reason']);
        $this->assertSame('inaccessible_or_not_found', $result['unresolved'][1]['reason']);
    }

    public function test_invalid_selection_and_incompatible_archive_contract_make_no_requests(): void
    {
        Http::fake();
        $inventory = app(Inventory::class);
        $this->assertFalse($inventory->boards('88', ['101', 'not-an-id'], $this->contract())['ok']);
        $contract = $this->contract();
        $contract['state_values'] = ['active'];
        $this->assertSame('schema_incompatible', $inventory->boards('88', ['101'], $contract)['error']);
        Http::assertNothingSent();
    }

    public function test_dependency_lookup_retains_only_names_states_counts_and_exact_identifiers(): void
    {
        $board = [...$this->board('101'), 'unexpected_private_value' => 'raw-private-marker'];
        Http::fakeSequence()->push($this->payload([$board]), 200, $this->headers());
        $result = app(Inventory::class)->references('88', ['101']);

        $this->assertTrue($result['ok']);
        $this->assertSame([['id' => '101', 'name' => 'Fictional leads', 'state' => 'active', 'items_count' => 3]], $result['boards']);
        $this->assertStringNotContainsString('raw-private-marker', json_encode($result));
        Http::assertSent(fn ($request) => ! str_contains($request['query'], 'columns'));
    }

    private function payload(array $boards): array
    {
        return ['data' => ['me' => ['account' => ['id' => '88']], 'version' => ['value' => '2026-10'], 'boards' => $boards]];
    }

    private function board(string $id): array
    {
        return ['id' => $id, 'name' => 'Fictional leads', 'state' => 'active', 'items_count' => 3,
            'columns' => [['id' => 'name', 'title' => 'Name', 'type' => 'name', 'archived' => false, 'settings' => []]]];
    }

    private function contract(): array
    {
        return ['query' => ['boards' => ['args' => [['name' => 'ids'], ['name' => 'state']]]],
            'board' => array_fill_keys(['id', 'name', 'state', 'items_count', 'columns'], []),
            'column' => array_fill_keys(['id', 'title', 'type', 'archived', 'settings'], []), 'state_values' => ['active', 'all']];
    }

    private function headers(): array
    {
        return ['API-Version' => '2026-10'];
    }
}
