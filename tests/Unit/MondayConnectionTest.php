<?php

namespace Tests\Unit;

use App\Integrations\Monday\Connection;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\StreamDecoratorTrait;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

/** No database access or real monday requests are needed for connection checks. */
class MondayConnectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2026-10']);
        Http::preventStrayRequests();
    }

    public function test_it_queries_only_account_identity_with_bounded_secure_transport(): void
    {
        Http::fake(function ($request, $options) {
            $this->assertSame('https://api.monday.com/v2', $request->url());
            $this->assertSame('POST', $request->method());
            $this->assertSame(['query' => Connection::QUERY], $request->data());
            $this->assertSame(['fictional-monday-token'], $request->header('Authorization'));
            $this->assertSame(['2026-10'], $request->header('API-Version'));
            $this->assertTrue($options['verify']);
            $this->assertFalse($options['allow_redirects']);
            $this->assertTrue($options['stream']);
            $this->assertSame(5, $options['connect_timeout']);
            $this->assertSame(20, $options['timeout']);
            $this->assertSame(2, $options['read_timeout']);

            return Http::response($this->success(), 200, ['API-Version' => '2026-10']);
        });

        $this->assertSame(['ok' => true, 'api_version' => '2026-10', 'account' => [
            'id' => '12345678901234567890', 'name' => 'חשבון הדגמה', 'slug' => 'fictional-account',
        ]], $this->runCommand(0));
        Http::assertSentCount(1);
    }

    public function test_missing_empty_and_whitespace_tokens_make_no_request(): void
    {
        Http::fake();
        foreach ([null, '', '  '] as $token) {
            config(['services.monday.token' => $token]);
            $this->assertSame('token_missing', $this->runCommand(1)['error']);
        }
        Http::assertNothingSent();
    }

    public function test_bad_header_input_and_unreviewed_versions_make_no_request(): void
    {
        Http::fake();
        foreach ([' fictional-monday-token', "fictional\r\nAuthorization: another"] as $token) {
            config(['services.monday.token' => $token]);
            $this->assertSame('configuration_error', $this->runCommand(1)['error']);
        }
        config(['services.monday.token' => 'fictional-monday-token', 'services.monday.api_version' => '2027-01']);
        $this->assertSame('configuration_error', $this->runCommand(1)['error']);
        Http::assertNothingSent();
    }

    public function test_http_failures_are_sanitized_and_not_retried_or_followed(): void
    {
        Http::fakeSequence()
            ->push('raw-private-value', 401)
            ->push('raw-private-value', 403)
            ->push('raw-private-value', 429)
            ->push('raw-private-value', 500)
            ->push('raw-private-value', 302, ['Location' => 'https://unrelated.example.test']);
        foreach (['authentication_failed', 'access_denied', 'rate_limited', 'http_error', 'http_error'] as $category) {
            $this->assertSame($category, $this->runCommand(1)['error']);
        }
        Http::assertSentCount(5);
    }

    public function test_partial_graphql_errors_do_not_return_account_data_or_raw_messages(): void
    {
        Http::fakeSequence()
            ->push([...$this->success(), 'errors' => [['message' => 'raw-private-value']]], 200, ['API-Version' => '2026-10'])
            ->push(['errors' => [['message' => 'raw-private-value', 'extensions' => ['code' => 'UserUnauthorizedException']]]], 200, ['API-Version' => '2026-10'])
            ->push(['errors' => [['message' => 'raw-private-value', 'extensions' => ['code' => 'PERMISSION_DENIED']]]], 200, ['API-Version' => '2026-10'])
            ->push(['errors' => [['message' => 'raw-private-value', 'extensions' => ['code' => 'RATE_LIMIT_EXCEEDED']]]], 200, ['API-Version' => '2026-10']);
        foreach (['api_error', 'authentication_failed', 'access_denied', 'rate_limited'] as $category) {
            $result = $this->runCommand(1);
            $this->assertSame($category, $result['error']);
            $this->assertArrayNotHasKey('account', $result);
        }
        Http::assertSentCount(4);
    }

    public function test_network_failure_does_not_expose_the_original_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('raw-private-value Authorization: fictional-monday-token'));
        $this->assertSame('network_error', $this->runCommand(1)['error']);
    }

    public function test_stream_cleanup_failure_is_also_sanitized(): void
    {
        $body = new class(Utils::streamFor(json_encode($this->success(), JSON_THROW_ON_ERROR))) implements StreamInterface
        {
            use StreamDecoratorTrait;

            public function close(): void
            {
                throw new \RuntimeException('raw-private-value Authorization: fictional-monday-token');
            }
        };
        Http::fake(fn () => Create::promiseFor(new Response(200, ['API-Version' => '2026-10'], $body)));
        $this->assertSame('network_error', $this->runCommand(1)['error']);
    }

    public function test_malformed_missing_or_oversized_metadata_fails_closed(): void
    {
        Http::fakeSequence()
            ->push('raw-private-value', 200, ['API-Version' => '2026-10'])
            ->push(['data' => ['me' => null]], 200, ['API-Version' => '2026-10'])
            ->push(['data' => ['me' => ['id' => '7', 'account' => ['id' => '8', 'name' => "unsafe\x1Bname", 'slug' => 'fictional']]]], 200, ['API-Version' => '2026-10'])
            ->push(['errors' => 'raw-private-value'], 200, ['API-Version' => '2026-10'])
            ->push(str_repeat('x', 65537), 200, ['API-Version' => '2026-10']);
        foreach (['invalid_response', 'invalid_response', 'invalid_response', 'invalid_response', 'response_too_large'] as $category) {
            $this->assertSame($category, $this->runCommand(1)['error']);
        }
    }

    public function test_version_header_and_schema_version_must_both_match_the_pin(): void
    {
        Http::fakeSequence()
            ->push($this->success(), 200)
            ->push($this->success(), 200, ['API-Version' => '2027-01'])
            ->push(['data' => [...$this->success()['data'], 'version' => ['value' => '2027-01']]], 200, ['API-Version' => '2026-10']);
        foreach (range(1, 3) as $_) {
            $this->assertSame('version_mismatch', $this->runCommand(1)['error']);
        }
    }

    public function test_a_second_check_uses_the_current_private_setting_not_a_cached_token(): void
    {
        Http::fakeSequence()
            ->push($this->success(), 200, ['API-Version' => '2026-10'])
            ->push($this->success(), 200, ['API-Version' => '2026-10']);
        $connection = app(Connection::class);
        $this->assertTrue($connection->check()['ok']);
        config(['services.monday.token' => 'second-fictional-token']);
        $this->assertTrue($connection->check()['ok']);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'second-fictional-token'));
    }

    private function success(): array
    {
        return ['data' => ['me' => ['id' => '77', 'account' => [
            'id' => '12345678901234567890', 'name' => 'חשבון הדגמה', 'slug' => 'fictional-account',
        ]], 'version' => ['value' => '2026-10']]];
    }

    private function runCommand(int $expectedExit): array
    {
        $this->assertSame($expectedExit, Artisan::call('crm:monday-check'));
        $output = Artisan::output();
        $this->assertStringNotContainsString('fictional-monday-token', $output);
        $this->assertStringNotContainsString('raw-private-value', $output);

        return json_decode($output, true, 16, JSON_THROW_ON_ERROR);
    }
}
