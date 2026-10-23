<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
$app = require __DIR__.'/../bootstrap/app.php';
$installation = $argv[1] ?? 'alpha';
if (! in_array($installation, ['alpha', 'beta'], true)) {
    throw new RuntimeException('Local examples only.');
}
$app->loadEnvironmentFrom('.env.'.$installation);
$app->make(Kernel::class)->bootstrap();
FictionalCrmFixtures::assertLocalExample($installation);
$file = base_path('.local-fixtures.json');
$credentials = json_decode(file_get_contents($file), true);
foreach (['desktop', 'mobile'] as $size) {
    $key = 'tables_'.$installation.'_'.$size;
    $credentials[$key] ??= ['email' => 'table-'.$size.'@'.$installation.'.example.test', 'password' => bin2hex(random_bytes(18))];
    $user = User::firstOrNew(['email' => $credentials[$key]['email']]);
    if (! $user->exists) {
        $user->forceFill(['name' => FictionalCrmFixtures::tableReviewerName($size), 'password' => $credentials[$key]['password'], 'email_verified_at' => now(), 'active' => true])->save();
        $user->assignRole('Employee');
        if ($installation === 'beta') {
            $user->givePermissionTo(['service-details.view', 'service-details.edit']);
        }
    }
    // This command resets only the dedicated browser accounts' table layouts.
    FictionalCrmFixtures::enrich($user, ['name' => FictionalCrmFixtures::tableReviewerName($size), 'display_preferences' => null]);
    FictionalCrmFixtures::sync($user, $installation, true, true);
    FictionalCrmFixtures::localizeTaskTitles($user);
}
file_put_contents($file, json_encode($credentials, JSON_PRETTY_PRINT));
chmod($file, 0600);
echo "Isolated $installation table browser fixtures ready.\n";
