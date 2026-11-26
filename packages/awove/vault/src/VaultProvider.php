<?php

namespace Awove\Vault;

use Awove\Core\Modules;
use Awove\Vault\Http\NoStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Command;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class VaultProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/vault.php', 'vault');
    }

    public function boot(): void
    {
        $this->app->make(Modules::class)->register(new VaultModule);
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        TrimStrings::skipWhen(fn (Request $request) => $request->is('vault', 'vault/*'));
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request) => $request->is('vault', 'vault/*'));
        $handler = $this->app->make(ExceptionHandler::class);
        // Debug exception pages and error reporters must never receive Vault values.
        $handler->reportable(function (\Throwable $error) {
            return request()->is('vault', 'vault/*') ? false : null;
        });
        $handler->renderable(function (\Throwable $error, Request $request) {
            if (! $request->is('vault', 'vault/*')) {
                return null;
            }
            if ($error instanceof AuthenticationException && ! $request->is('vault/api/*')) {
                return NoStore::headers(redirect()->guest(route('login')));
            }
            $status = match (true) {
                $error instanceof ValidationException => 422,
                $error instanceof AuthenticationException => 401,
                $error instanceof AuthorizationException => 403,
                $error instanceof HttpExceptionInterface => $error->getStatusCode(),
                default => 500,
            };
            $message = match ($status) {
                401 => 'Sign in to the CRM to continue.',
                403, 404 => 'Vault action unavailable.',
                409 => 'Vault changed. Reload before saving.',
                422 => 'Check the vault form.',
                423 => 'Vault is locked. Unlock it and try again.',
                429 => 'Vault request rate limit reached.',
                default => 'Unable to complete the vault request.',
            };
            $body = ['message' => (string) __($message)];
            if ($error instanceof ValidationException) {
                $body['errors'] = $error->errors();
            }

            return NoStore::headers(response()->json($body, $status));
        });
        Event::listen(Logout::class, RevokeSessionUnlocks::class);
        AuthorizationRevocations::register();
        $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../routes/data.php');
        if ($this->app->runningInConsole()) {
            $classes = array_map(fn ($path) => __NAMESPACE__.'\\Console\\'.basename($path, '.php'), glob(__DIR__.'/Console/*.php') ?: []);
            $this->commands(array_values(array_filter($classes, fn ($class) => is_subclass_of($class, Command::class) && ! (new \ReflectionClass($class))->isAbstract())));
        }
    }
}
