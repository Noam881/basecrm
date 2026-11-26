<?php

use App\Models\User;
use Awove\Core\Installation;
use Awove\Vault\Models\Challenge;
use Awove\Vault\Unlocks;
use Illuminate\Http\Request;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Mail\Transport\LogTransport;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    Notification::fake();
    config(['mail.driver' => null, 'mail.default' => 'smtp', 'mail.mailers' => [
        'smtp' => ['transport' => 'smtp', 'host' => 'mail.example.test', 'port' => 587, 'scheme' => 'smtp', 'url' => null],
        'array' => ['transport' => 'array'],
        'log' => ['transport' => 'log'],
    ]]);
    app('mail.manager')->forgetMailers();
});

afterEach(function () {
    app()->instance('env', 'testing');
    if (isset($this->vaultMailManifest)) {
        unlink($this->vaultMailManifest);
    }
});

function vmtRefused(): HttpException
{
    try {
        Unlocks::assertMailTransport();
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(503)
            ->and($error->getPrevious())->toBeNull();

        return $error;
    }
    throw new RuntimeException('An unsafe Vault mail transport was accepted.');
}

it('accepts an explicitly configured direct SMTP transport under its configured name', function (string $name) {
    app()->instance('env', 'production');
    config(['mail.default' => $name, 'mail.mailers.'.$name => config('mail.mailers.smtp')]);
    Unlocks::assertMailTransport();
    Notification::assertNothingSent();
})->with(['smtp', 'transactional']);

it('accepts direct SMTP URL settings using Laravel precedence without sending mail', function () {
    app()->instance('env', 'production');
    config(['mail.mailers.smtp.url' => 'smtp://fictional-user:fictional-password@mail.example.test:465?scheme=smtps']);
    Unlocks::assertMailTransport();
    Notification::assertNothingSent();
});

it('accepts a direct native sendmail transport without starting a process', function (string $driver) {
    app()->instance('env', 'production');
    config(['mail.default' => 'native', 'mail.mailers.native' => ['transport' => $driver, 'path' => '/usr/sbin/sendmail -bs -i']]);
    Unlocks::assertMailTransport();
    Notification::assertNothingSent();
})->with(['sendmail', 'mail']);

it('rejects non-delivering unknown and composite transports before resolving their branches', function (string $driver) {
    app()->instance('env', 'production');
    config(['mail.default' => 'transactional', 'mail.mailers.transactional' => ['transport' => $driver, 'mailers' => ['smtp', 'log', 'transactional']]]);
    vmtRefused();
    Notification::assertNothingSent();
})->with(['log', 'array', 'custom-delivery', 'failover', 'roundrobin']);

it('rejects even all-delivery composites and nested fallback chains', function (array $children) {
    app()->instance('env', 'production');
    config(['mail.default' => 'primary',
        'mail.mailers.primary' => ['transport' => 'failover', 'mailers' => $children],
        'mail.mailers.nested' => ['transport' => 'roundrobin', 'mailers' => ['smtp', 'log']],
    ]);
    vmtRefused();
})->with([
    'all delivery' => [['smtp', 'smtp']],
    'logging fallback' => [['smtp', 'log']],
    'nested logging fallback' => [['smtp', 'nested']],
    'empty chain' => [[]],
    'cycle' => [['primary']],
]);

it('rejects unsafe or malformed SMTP DSN overrides without disclosing credentials', function (mixed $url) {
    app()->instance('env', 'production');
    config(['mail.mailers.smtp.url' => $url]);
    expect(vmtRefused()->getMessage())->not->toContain('FICTIONAL_DSN_SECRET');
    Notification::assertNothingSent();
})->with([
    'logging DSN' => ['log://FICTIONAL_DSN_SECRET@localhost'],
    'array DSN' => ['array://localhost'],
    'composite DSN' => ['failover://localhost'],
    'non-SMTP DSN' => ['https://mail.example.test'],
    'query driver override' => ['smtp://FICTIONAL_DSN_SECRET@mail.example.test?driver=log'],
    'query host array' => ['smtp://mail.example.test?host[]=localhost'],
    'query scheme override' => ['smtp://mail.example.test?scheme=log'],
    'malformed DSN' => ['smtp://FICTIONAL_DSN_SECRET@localhost:invalid'],
    'wrong type' => [['smtp://mail.example.test']],
]);

it('rejects undefined mailers legacy driver overrides and a non-SMTP declaration with a URL', function () {
    app()->instance('env', 'production');
    config(['mail.default' => 'missing']);
    vmtRefused();
    config(['mail.default' => 'smtp', 'mail.driver' => 'log']);
    vmtRefused();
    config(['mail.driver' => null, 'mail.mailers.smtp.transport' => 'log', 'mail.mailers.smtp.url' => 'smtp://mail.example.test']);
    vmtRefused();
});

it('rejects a custom log or array transport registered under an approved name', function (string $kind) {
    app()->instance('env', 'production');
    app('mail.manager')->extend('smtp', fn () => $kind === 'log' ? new LogTransport(app('log')) : new ArrayTransport);
    vmtRefused();
    Notification::assertNothingSent();
})->with(['log', 'array']);

it('rejects a cached log transport after configuration changes to SMTP', function () {
    app()->instance('env', 'production');
    config(['mail.default' => 'transactional', 'mail.mailers.transactional' => ['transport' => 'log']]);
    app('mail.manager')->mailer('transactional');
    config(['mail.mailers.transactional' => config('mail.mailers.smtp')]);
    vmtRefused();
});

it('replaces transport construction failures with a generic unchained error', function () {
    app()->instance('env', 'production');
    app('mail.manager')->extend('smtp', function () {
        throw new RuntimeException('FICTIONAL_DSN_SECRET');
    });
    expect(vmtRefused()->getMessage())->not->toContain('FICTIONAL_DSN_SECRET');
});

it('retains only the named non-delivering array transport in tests', function () {
    config(['mail.default' => 'array']);
    Unlocks::assertMailTransport();
    config(['mail.default' => 'smtp']);
    vmtRefused();
    config(['mail.default' => 'array', 'mail.mailers.array.transport' => 'smtp']);
    vmtRefused();
});

it('retains only the named loopback SMTP transport locally including effective DSN host', function () {
    app()->instance('env', 'local');
    config(['mail.mailers.smtp.host' => '127.0.0.1']);
    Unlocks::assertMailTransport();
    config(['mail.mailers.smtp.url' => 'smtp://mail.example.test:587']);
    vmtRefused();
    config(['mail.mailers.smtp.url' => null, 'mail.default' => 'transactional', 'mail.mailers.transactional' => config('mail.mailers.smtp')]);
    vmtRefused();
});

it('rejects an unsafe transport before creating a password challenge or notifying anyone', function () {
    $manifest = Installation::manifest();
    $manifest['modules'] = ['vault'];
    $this->vaultMailManifest = tempnam(sys_get_temp_dir(), 'vault-mail-');
    file_put_contents($this->vaultMailManifest, json_encode($manifest));
    config(['core.manifest' => $this->vaultMailManifest]);
    Permission::findOrCreate('vault.list', 'web');
    $user = User::factory()->create(['name' => 'Tamar Levi', 'email' => 'vault-mail@fictional.example.test', 'active' => true]);
    $user->givePermissionTo('vault.list');
    $request = Request::create('/vault/api/auth/password', 'POST');
    $session = new Store('vault-mail-test', new ArraySessionHandler(120));
    $session->start();
    $request->setLaravelSession($session);
    $request->setUserResolver(fn () => $user);
    app()->instance('env', 'production');
    config(['mail.default' => 'transactional', 'mail.mailers.transactional' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
    try {
        app(Unlocks::class)->password($request, ['password' => 'Fictional-password-only']);
        throw new RuntimeException('Unsafe mail configuration accepted.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(503);
    }
    expect(Challenge::count())->toBe(0);
    Notification::assertNothingSent();
});
