<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

// Local-only synthetic fixtures. Random private credentials, no production defaults.
require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$installation = $argv[1] ?? 'alpha';
if (! in_array($installation, ['alpha', 'beta'], true)) {
    throw new RuntimeException('Unknown example.');
}
$app->loadEnvironmentFrom('.env.'.$installation);
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample($installation);
$path = base_path('.local-fixtures.json');
$credentials = is_file($path) ? json_decode(file_get_contents($path), true) : [];
$credentials[$installation]['password'] ??= bin2hex(random_bytes(18));
unset($credentials['password']);
$email = 'reviewer@'.$installation.'.example.test';
$user = User::firstOrNew(['email' => $email]);
if (! $user->exists) {
    $user->forceFill(['name' => FictionalCrmFixtures::reviewerName($installation), 'email_verified_at' => now(), 'password' => $credentials[$installation]['password'], 'active' => true])->save();
    $user->assignRole('Employee');
    if ($installation === 'beta') {
        $user->givePermissionTo(['service-details.view', 'service-details.edit']);
    }
}
// Existing account credentials, MFA and personal layouts are preserved.
FictionalCrmFixtures::enrich($user, ['name' => FictionalCrmFixtures::reviewerName($installation)]);
$credentials[$installation]['email'] = $email;
file_put_contents($path, json_encode($credentials, JSON_PRETTY_PRINT));
chmod($path, 0600);
FictionalCrmFixtures::sync($user, $installation, false, true);
FictionalCrmFixtures::localizeBrowserRows($user);
FictionalCrmFixtures::localizeTaskTitles($user);
echo "Synthetic $installation fixtures ready. Credentials are in .local-fixtures.json (private, ignored).\n";
