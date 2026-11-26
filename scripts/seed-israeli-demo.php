<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/fictional-fixtures.php';
require __DIR__.'/israeli-demo-catalog.php';
require __DIR__.'/israeli-demo-leads.php';

$installation = $argv[1] ?? 'alpha';
if (! in_array($installation, ['alpha', 'beta'], true) || array_diff(array_slice($argv, 2), ['--apply'])) {
    throw new RuntimeException('Usage: php scripts/seed-israeli-demo.php alpha|beta [--apply]');
}
$app = require __DIR__.'/../bootstrap/app.php';
$app->loadEnvironmentFrom('.env.'.$installation);
$app->make(Kernel::class)->bootstrap();
$result = IsraeliDemoLeads::run($installation, in_array('--apply', $argv, true));
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
