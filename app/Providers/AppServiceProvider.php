<?php

namespace App\Providers;

use App\Auth\MagicLogin;
use App\Models\User;
use App\Notifications\MagicLoginLink;
use Awove\Core\Events\SignInLinkRequested;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $id = config('core.installation');
        $root = storage_path('installations/'.$id);
        config(['filesystems.disks.local.root' => $root.'/private', 'filesystems.disks.public.root' => $root.'/public']);
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        Event::listen(SignInLinkRequested::class, function (SignInLinkRequested $event): void {
            $user = User::where('active', true)->find($event->userId);
            if ($user) {
                $link = app(MagicLogin::class)->issue($user);
                $user->notify(new MagicLoginLink($link['url']));
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );
    }
}
