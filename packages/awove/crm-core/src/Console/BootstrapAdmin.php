<?php

namespace Awove\Core\Console;

use Awove\Core\Events\SignInLinkRequested;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BootstrapAdmin extends Command
{
    protected $signature = 'crm:bootstrap-admin {email} {--name=Administrator}';

    protected $description = 'Create the first administrator and send a time-limited email sign-in link';

    public function handle(): int
    {
        $model = config('auth.providers.users.model');
        validator(['email' => $this->argument('email')], ['email' => 'required|email'])->validate();
        $user = DB::transaction(function () use ($model) {
            DB::select('SELECT pg_advisory_xact_lock(175542201)');
            if ($model::role('Administrator')->exists()) {
                return null;
            }$u = $model::forceCreate(['email' => strtolower($this->argument('email')), 'name' => $this->option('name'), 'password' => Str::random(64), 'email_verified_at' => now()]);
            $u->assignRole('Administrator');

            return $u;
        });
        if (! $user) {
            $this->error('An administrator already exists. Use administrator-managed invitations.');

            return self::FAILURE;
        }
        event(new SignInLinkRequested($user->id));
        $this->info('Administrator created. Check your email for the sign-in link. No password is required.');

        return self::SUCCESS;
    }
}
