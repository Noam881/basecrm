<?php

require_once __DIR__.'/../../scripts/browser-fixtures.php';

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
});

it('matches exact run markers only for recognized clean fictional names and the selected project', function () {
    $timestamp = '1792693755194';
    foreach (['desktop', 'mobile'] as $project) {
        foreach (['Oren Solutions' => "office.$project-$timestamp@demo.example.test", 'Levi Solutions' => "company-$project-$timestamp@example.test"] as $name => $email) {
            expect(BrowserFixtures::identityMatches('companies', $name, $email, $project))->toBeTrue();
            expect(BrowserFixtures::identityMatches('companies', $name, $email, $project === 'desktop' ? 'mobile' : 'desktop'))->toBeFalse();
        }
        foreach (['Uri Ben David' => "uri.$project-$timestamp@demo.example.test", 'Shira Levi' => "contact-$project-$timestamp@example.test", 'שירה לוי' => "changed-contact-$project-$timestamp@example.test"] as $name => $email) {
            expect(BrowserFixtures::identityMatches('contacts', $name, $email, $project))->toBeTrue();
        }
    }
    expect(BrowserFixtures::identityMatches('contacts', 'Shira Levi', 'CONTACT-desktop-1792693755194@EXAMPLE.TEST', 'desktop'))->toBeTrue();
});

it('preserves seeded and ordinary reviewer identities even when their display names resemble browser records', function () {
    foreach ([
        ['contacts', 'Noa Levi', 'noa.levi@alpha.example.test'],
        ['contacts', 'Daniel Cohen', 'daniel.cohen@alpha.example.test'],
        ['contacts', 'Yael Mizrahi', 'yael.mizrahi@alpha.example.test'],
        ['companies', 'Rimon Studio', 'hello@rimon.alpha.example.test'],
        ['companies', 'Carmel Supply', 'hello@carmel.alpha.example.test'],
        ['companies', 'Shaked & Co', 'hello@shaked.alpha.example.test'],
        ['contacts', 'Shira Levi', 'shira@example.test'],
        ['companies', 'Oren Solutions', 'office@example.test'],
        ['contacts', 'Someone Else', 'contact-desktop-1792693755194@example.test'],
        ['contacts', 'Shira Levi', 'contact-desktop-1792693755194@example.test.untrusted'],
        ['contacts', 'Shira Levi', 'prefix-contact-desktop-1792693755194@example.test'],
        ['contacts', 'Shira Levi', 'contact-desktop-17926937551940@example.test'],
        ['contacts', 'Shira Levi', "contact-desktop-1792693755194@example.test\n"],
        ['companies', 'Levi Solutions', 'company-desktop-1792693755194@example.test.untrusted'],
    ] as [$kind, $name, $email]) {
        expect(BrowserFixtures::identityMatches($kind, $name, $email))->toBeFalse();
        expect(BrowserFixtures::identityMatches($kind, $name, $email, legacy: true))->toBeFalse();
    }
});

it('requires explicit legacy mode for exact timestamped browser labels without run emails', function () {
    foreach ([
        ['companies', 'Company desktop-1792693755194'],
        ['companies', 'אורן פתרונות — desktop-1792693755194'],
        ['companies', 'Oren Solutions — desktop-1792693755194'],
        ['companies', 'Levi Solutions — desktop-1792693755194'],
        ['contacts', 'Person desktop-1792693755194'],
        ['contacts', 'אורי בן דוד — desktop-1792693755194'],
        ['contacts', 'Uri Ben David — desktop-1792693755194'],
        ['contacts', 'Shira Levi — desktop-1792693755194'],
    ] as [$kind, $name]) {
        expect(BrowserFixtures::identityMatches($kind, $name, null))->toBeFalse();
        expect(BrowserFixtures::identityMatches($kind, $name, null, 'desktop', true))->toBeTrue();
        expect(BrowserFixtures::identityMatches($kind, $name, null, 'mobile', true))->toBeFalse();
        expect(BrowserFixtures::identityMatches($kind, $name.' extra', null, legacy: true))->toBeFalse();
    }
});

it('fails closed for unsupported projects, kinds and non-example databases', function () {
    expect(fn () => BrowserFixtures::identityMatches('contacts', 'Shira Levi', null, 'production'))->toThrow(InvalidArgumentException::class);
    expect(fn () => BrowserFixtures::identityMatches('users', 'Shira Levi', null))->toThrow(InvalidArgumentException::class);
    expect(fn () => BrowserFixtures::plan('alpha'))->toThrow(RuntimeException::class);
    expect(fn () => BrowserFixtures::plan('production'))->toThrow(RuntimeException::class);
});
