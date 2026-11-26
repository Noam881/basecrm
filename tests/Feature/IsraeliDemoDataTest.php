<?php

use Awove\Core\ContactDetails;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Models\Lead;
use Awove\Core\Services\Sales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

require_once __DIR__.'/../../scripts/fictional-fixtures.php';
require_once __DIR__.'/../../scripts/israeli-demo-catalog.php';
require_once __DIR__.'/../../scripts/israeli-demo-leads.php';

it('provides twenty distinct fictional Israeli profiles with valid shared CRM fields', function () {
    $rows = IsraeliDemoCatalog::rows();
    expect($rows)->toHaveCount(20)
        ->and(array_unique(array_column($rows, 'key')))->toHaveCount(20)
        ->and(array_unique(array_map(fn ($row) => $row['contact']['email'], $rows)))->toHaveCount(20)
        ->and(array_unique(array_map(fn ($row) => $row['contact']['name'], $rows)))->toHaveCount(20)
        ->and(array_unique(array_map(fn ($row) => $row['lead']['status_id'], $rows)))->toContain('new', 'contacted', 'qualified', 'won', 'lost');
    foreach ($rows as $row) {
        $contact = $row['contact'];
        expect($contact['name'])->not->toMatch('/\p{Hebrew}|desktop|mobile|\d{13}/u')
            ->and($contact['country'])->toBe('Israel')
            ->and($contact['email'])->toEndWith('@demo.example.test')
            ->and($row['company']['email'])->toEndWith('@demo.example.test')
            ->and($contact['phone'])->toStartWith('+972 ')
            ->and($contact['secondary_phone'])->toStartWith('+972 ')
            ->and($row['company']['registration_number'])->toStartWith('DEMO-')
            ->and($row['notes'])->not->toBeEmpty();
        validator($contact, [
            'name' => 'required|string|max:120', 'email' => 'required|email|max:160',
            'phone' => 'required|string|max:40', 'secondary_phone' => 'required|string|max:40',
            'address' => 'required|string|max:500', 'city' => 'required|string|max:120',
            'preferred_contact_method' => [Rule::in(array_keys(ContactDetails::METHODS))],
            'secondary_phone_label' => [Rule::in(array_keys(ContactDetails::PHONE_LABELS))],
            'preferred_contact_times.*' => [Rule::in(array_keys(ContactDetails::TIMES))],
            'preferred_contact_days.*' => [Rule::in(array_keys(ContactDetails::DAYS))],
            'source' => [Rule::in(array_keys(ContactDetails::SOURCES))],
        ])->validate();
        validator($row['lead'], Sales::opportunityRules())->validate();
        if ($row['lead']['status_id'] === 'won') {
            expect($row['age_days'])->toBeGreaterThanOrEqual(12)->and($row['lead']['final_value'])->not->toBeNull();
        }
        if ($row['follow_up']) {
            expect($row['follow_up']['hour'])->toBeGreaterThanOrEqual(9)->toBeLessThan(15);
        }
    }
});

it('refuses the demo writer outside explicitly guarded local Alpha and Beta databases', function () {
    expect(fn () => IsraeliDemoLeads::run('alpha', true))->toThrow(RuntimeException::class)
        ->and(fn () => IsraeliDemoLeads::run('production', true))->toThrow(RuntimeException::class)
        ->and(Lead::count())->toBe(0)->and(Contact::count())->toBe(0)->and(Company::count())->toBe(0);
});

it('rejects local-looking configuration when the resolved connection targets a different database', function () {
    $connection = DB::connection();
    expect($connection->getDatabaseName())->toBe('base_test');
    $environment = app()->environment();
    $installation = config('core.installation');
    $settings = config('database.connections.pgsql');
    try {
        app()->detectEnvironment(fn () => 'local');
        config(['core.installation' => 'alpha', 'database.connections.pgsql.database' => 'base_alpha', 'database.connections.pgsql.host' => '127.0.0.1']);
        // The established PDO connection remains base_test: never reconnect using altered settings.
        FictionalCrmFixtures::assertLocalExample('alpha');
        expect(fn () => IsraeliDemoLeads::run('alpha', true))->toThrow(RuntimeException::class, 'resolved connection');
        expect($connection->getDatabaseName())->toBe('base_test');
    } finally {
        config(['core.installation' => $installation, 'database.connections.pgsql' => $settings]);
        app()->detectEnvironment(fn () => $environment);
    }
    expect(Lead::count())->toBe(0)->and(Contact::count())->toBe(0)->and(Company::count())->toBe(0);
});
