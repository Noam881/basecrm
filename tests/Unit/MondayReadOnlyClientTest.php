<?php

namespace Tests\Unit;

use App\Integrations\Monday\ReadOnlyClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MondayReadOnlyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2026-10']);
        Http::preventStrayRequests();
    }

    public function test_named_operation_and_variables_are_sent_separately_without_interpolation(): void
    {
        $query = 'query BoardMetadata($ids: [ID!]!, $text: String) { boards(ids: $ids) { id name } }';
        $variables = ['ids' => ['18392036417'], 'text' => '} mutation Bad { delete_item(item_id: 7) { id } }'];
        Http::fake(function ($request) use ($query, $variables) {
            $this->assertSame(['query' => $query, 'operationName' => 'BoardMetadata', 'variables' => $variables], $request->data());

            return Http::response(['data' => ['boards' => [['id' => '18392036417', 'name' => 'Fictional board']]]], 200, ['API-Version' => '2026-10']);
        });
        $result = app(ReadOnlyClient::class)->query($query, $variables);
        $this->assertTrue($result['ok']);
        $this->assertSame('18392036417', $result['data']['boards'][0]['id']);
        Http::assertSentCount(1);
    }

    public function test_mutations_subscriptions_anonymous_queries_and_invalid_limits_never_send(): void
    {
        Http::fake();
        foreach (['{ me { id } }', 'query { me { id } }', 'mutation Delete { delete_item(item_id: 7) { id } }',
            'subscription Changes { items { id } }'] as $query) {
            $this->assertSame(['ok' => false, 'error' => 'configuration_error'], app(ReadOnlyClient::class)->query($query));
        }
        foreach ([0, -1, 1048577] as $maximum) {
            $this->assertSame(['ok' => false, 'error' => 'configuration_error'],
                app(ReadOnlyClient::class)->query('query Metadata { __typename }', [], $maximum));
        }
        Http::assertNothingSent();
    }

    public function test_an_explicit_query_name_prevents_selection_of_another_operation(): void
    {
        $query = 'query Safe { me { id } } mutation Delete { delete_item(item_id: 7) { id } }';
        Http::fake(function ($request) use ($query) {
            $this->assertSame(['query' => $query, 'operationName' => 'Safe'], $request->data());

            return Http::response(['data' => ['me' => ['id' => '77']]], 200, ['API-Version' => '2026-10']);
        });
        $this->assertTrue(app(ReadOnlyClient::class)->query($query)['ok']);
    }

    public function test_exact_response_limit_succeeds_and_one_extra_byte_fails(): void
    {
        $raw = json_encode(['data' => ['value' => 'fictional']], JSON_THROW_ON_ERROR);
        Http::fakeSequence()
            ->push($raw, 200, ['API-Version' => '2026-10'])
            ->push($raw.' ', 200, ['API-Version' => '2026-10']);
        $client = app(ReadOnlyClient::class);
        $this->assertSame(['ok' => true, 'data' => ['value' => 'fictional']], $client->query('query Metadata { value }', [], strlen($raw)));
        $this->assertSame(['ok' => false, 'error' => 'response_too_large'], $client->query('query Metadata { value }', [], strlen($raw)));
    }

    public function test_larger_metadata_response_requires_an_explicit_bounded_allowance(): void
    {
        Http::fakeSequence()
            ->push(['data' => ['value' => str_repeat('x', 65537)]], 200, ['API-Version' => '2026-10'])
            ->push(['data' => ['value' => str_repeat('x', 65537)]], 200, ['API-Version' => '2026-10']);
        $client = app(ReadOnlyClient::class);
        $this->assertSame(['ok' => false, 'error' => 'response_too_large'], $client->query('query Metadata { value }'));
        $this->assertTrue($client->query('query Metadata { value }', [], 1048576)['ok']);
    }

    public function test_non_map_variables_and_malformed_graphql_data_are_rejected(): void
    {
        Http::fakeSequence()
            ->push(['data' => null], 200, ['API-Version' => '2026-10'])
            ->push(['data' => [['id' => '1']]], 200, ['API-Version' => '2026-10']);
        $client = app(ReadOnlyClient::class);
        foreach ([['unexpected-list'], ['bad-key' => 'value'], ['text' => "\xFF"]] as $variables) {
            $this->assertSame(['ok' => false, 'error' => 'configuration_error'], $client->query('query Metadata { value }', $variables));
        }
        foreach (range(1, 2) as $_) {
            $this->assertSame(['ok' => false, 'error' => 'invalid_response'], $client->query('query Metadata { value }'));
        }
        Http::assertSentCount(2);
    }
}
