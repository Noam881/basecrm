<?php

use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../scripts/browser-fixtures.php';
require_once __DIR__.'/../../scripts/browser-proposal-files.php';

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    Storage::fake('local');
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

it('matches the exact stage fixture name and run email without broadening other fixture names', function () {
    expect(BrowserFixtures::identityMatches('contacts', 'Tamar Ben Ami', 'tamar.stage.desktop-1792693755194@demo.example.test', 'desktop'))->toBeTrue()
        ->and(BrowserFixtures::identityMatches('contacts', 'Tamar Ben Ami', 'tamar.stage.desktop-1792693755194@demo.example.test', 'mobile'))->toBeFalse()
        ->and(BrowserFixtures::identityMatches('contacts', 'Someone Else', 'tamar.stage.desktop-1792693755194@demo.example.test', 'desktop'))->toBeFalse()
        ->and(BrowserFixtures::identityMatches('contacts', 'Tamar Ben Ami', 'contact-desktop-1792693755194@example.test', 'desktop'))->toBeFalse()
        ->and(BrowserFixtures::identityMatches('contacts', 'Tamar Ben Ami', 'tamar.stage.desktop-1792693755194@demo.example.test.untrusted', 'desktop'))->toBeFalse();
});

function browserProposalRow(int $leadId, string $uuid, string $content): array
{
    return ['lead_id' => $leadId, 'private_path' => 'proposals/'.$leadId.'/'.$uuid.'.pdf', 'sha256' => hash('sha256', $content), 'size_bytes' => strlen($content)];
}

it('backs up restores and removes only the exact selected private proposal bytes', function () {
    $content = '%PDF-1.4 Fictional proposal';
    $row = browserProposalRow(7, '7e3ee91c-00f9-407a-9722-27f86752c15b', $content);
    Storage::disk('local')->put($row['private_path'], $content, 'private');
    Storage::disk('local')->put('unrelated-private-file.pdf', 'Preserved fictional file.', 'private');
    $snapshot = BrowserProposalFiles::snapshot([$row]);
    expect($snapshot[$row['private_path']]['sha256'])->toBe($row['sha256']);
    BrowserProposalFiles::remove([$row]);
    Storage::disk('local')->assertMissing($row['private_path']);
    $created = BrowserProposalFiles::restore([$row], $snapshot);
    expect($created)->toBe([$row['private_path']])->and(Storage::disk('local')->get($row['private_path']))->toBe($content)
        ->and(Storage::disk('local')->getVisibility($row['private_path']))->toBe('private')
        ->and(Storage::disk('local')->get('unrelated-private-file.pdf'))->toBe('Preserved fictional file.');
    expect(BrowserProposalFiles::restore([$row], $snapshot))->toBe([]);
    BrowserProposalFiles::discardCreated($created, [$row]);
    Storage::disk('local')->assertMissing($row['private_path']);
    Storage::disk('local')->assertExists('unrelated-private-file.pdf');
});

it('refuses unsafe proposal paths invalid checksums and mismatched backup files before changing anything', function () {
    $content = '%PDF-1.4 Fictional proposal';
    $row = browserProposalRow(7, '7e3ee91c-00f9-407a-9722-27f86752c15b', $content);
    Storage::disk('local')->put($row['private_path'], $content, 'private');
    $snapshot = BrowserProposalFiles::snapshot([$row]);
    foreach (['../outside.pdf', '/absolute.pdf', 'proposals/8/7e3ee91c-00f9-407a-9722-27f86752c15b.pdf', 'proposals/7/../../outside.pdf', 'proposals/7/7e3ee91c-00f9-407a-9722-27f86752c15b.php'] as $path) {
        expect(BrowserProposalFiles::pathMatches($path, 7))->toBeFalse();
        expect(fn () => BrowserProposalFiles::snapshot([[...$row, 'private_path' => $path]]))->toThrow(RuntimeException::class);
    }
    $changed = $snapshot;
    $changed[$row['private_path']]['content'] = base64_encode('Changed bytes');
    expect(fn () => BrowserProposalFiles::restore([$row], $changed))->toThrow(RuntimeException::class);
    expect(fn () => BrowserProposalFiles::restore([$row], []))->toThrow(RuntimeException::class);
    expect(fn () => BrowserProposalFiles::snapshot([[...$row, 'sha256' => str_repeat('0', 64)]]))->toThrow(RuntimeException::class);
    expect(fn () => BrowserProposalFiles::snapshot([[...$row, 'size_bytes' => 10 * 1024 * 1024 + 1]]))->toThrow(RuntimeException::class);
    expect(Storage::disk('local')->get($row['private_path']))->toBe($content);
});

it('never overwrites a conflicting private file or creates another file before checking all recovery collisions', function () {
    $first = browserProposalRow(7, '7e3ee91c-00f9-407a-9722-27f86752c15b', 'First fictional proposal');
    $second = browserProposalRow(7, '7e3ee91c-00f9-407a-9722-27f86752c15c', 'Second fictional proposal');
    Storage::disk('local')->put($first['private_path'], 'First fictional proposal', 'private');
    Storage::disk('local')->put($second['private_path'], 'Second fictional proposal', 'private');
    $snapshot = BrowserProposalFiles::snapshot([$first, $second]);
    Storage::disk('local')->delete($first['private_path']);
    Storage::disk('local')->put($second['private_path'], 'Preserved conflicting bytes', 'private');
    expect(fn () => BrowserProposalFiles::restore([$first, $second], $snapshot))->toThrow(RuntimeException::class);
    Storage::disk('local')->assertMissing($first['private_path']);
    expect(Storage::disk('local')->get($second['private_path']))->toBe('Preserved conflicting bytes');
});

it('refuses local adapter symlink roots directory components and final files during every proposal recovery operation', function (string $kind) {
    $content = '%PDF-1.4 Preserved outside proposal';
    $row = browserProposalRow(7, '7e3ee91c-00f9-407a-9722-27f86752c15b', $content);
    $files = [$row['private_path'] => ['content' => base64_encode($content), 'sha256' => $row['sha256'], 'size_bytes' => $row['size_bytes']]];
    $local = Storage::disk('local');
    $outside = Storage::fake('proposal-outside');
    $outside->put(basename($row['private_path']), $content, 'private');
    $localRoot = rtrim($local->path(''), DIRECTORY_SEPARATOR);
    $outsideRoot = rtrim($outside->path(''), DIRECTORY_SEPARATOR);
    $heldRoot = $localRoot.'-proposal-held';
    if ($kind === 'root') {
        rename($localRoot, $heldRoot);
        $link = $localRoot;
        $target = $outsideRoot;
    } elseif ($kind === 'directory') {
        $local->makeDirectory('proposals');
        $link = $local->path('proposals/7');
        $target = $outsideRoot;
    } else {
        $local->makeDirectory('proposals/7');
        $link = $local->path($row['private_path']);
        $target = $outside->path(basename($row['private_path']));
    }
    try {
        expect(symlink($target, $link))->toBeTrue();
        expect(fn () => BrowserProposalFiles::snapshot([$row]))->toThrow(RuntimeException::class);
        expect(fn () => BrowserProposalFiles::restore([$row], $files))->toThrow(RuntimeException::class);
        expect(fn () => BrowserProposalFiles::remove([$row]))->toThrow(RuntimeException::class);
        expect($outside->get(basename($row['private_path'])))->toBe($content)
            ->and($outside->allFiles())->toBe([basename($row['private_path'])]);
    } finally {
        if (is_link($link)) {
            unlink($link);
        }
        if ($kind === 'root') {
            rename($heldRoot, $localRoot);
        }
    }
})->with(['root', 'directory', 'file']);
