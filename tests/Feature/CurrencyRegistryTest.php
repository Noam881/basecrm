<?php

use Awove\Core\Currency;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    expect(config('database.connections.pgsql.database'))->toBe('base_test');
    Schema::create('currency_fixture_records', function (Blueprint $table) {
        $table->id();
        $table->string('currency', 3)->nullable();
        $table->boolean('archived')->default(false);
    });
});
afterEach(function () {
    Schema::dropIfExists('currency_fixture_records');
});
it('guards retained monetary snapshots even without an enabled business module and never converts records', function () {
    $currency = app(Currency::class);
    $currency->register('currency-fixture', ['currency_fixture_records']);
    $currency->register('currency-fixture', ['currency_fixture_records', 'currency_fixture_not_migrated']);
    DB::table('currency_fixture_records')->insert(['currency' => Currency::current(), 'archived' => false]);
    expect($currency->consistent())->toBeTrue();
    $id = DB::table('currency_fixture_records')->insertGetId(['currency' => 'ZZZ', 'archived' => true]);
    $before = DB::table('currency_fixture_records')->orderBy('id')->get()->toJson();
    expect($currency->consistent())->toBeFalse()
        ->and(fn () => $currency->assertConsistent())->toThrow(ValidationException::class)
        ->and(DB::table('currency_fixture_records')->orderBy('id')->get()->toJson())->toBe($before);
    DB::table('currency_fixture_records')->where('id', $id)->update(['currency' => Currency::current()]);
    $currency->assertConsistent();
    expect($currency->consistent())->toBeTrue();
});
it('rejects invalid table identifiers atomically and supports absent optional package tables', function () {
    $currency = app(Currency::class);
    DB::table('currency_fixture_records')->insert(['currency' => 'ZZZ']);
    foreach ([['currency-fixture', ['currency_fixture_records', 'crm_users']], ['currency-fixture', ['currency_fixture_records; DROP TABLE users']], ['currency-fixture', []], ['invalid key', ['currency_fixture_records']]] as [$module, $tables]) {
        expect(fn () => $currency->register($module, $tables))->toThrow(InvalidArgumentException::class);
    }
    expect($currency->consistent())->toBeTrue();
    $currency->register('currency-fixture', ['currency_fixture_not_migrated']);
    expect($currency->consistent())->toBeTrue();
    $currency->register('currency-fixture', ['currency_fixture_records']);
    expect($currency->consistent())->toBeFalse();
});
it('does not silently combine monetary rows without a currency snapshot', function () {
    $currency = app(Currency::class);
    $currency->register('currency-fixture', ['currency_fixture_records']);
    DB::table('currency_fixture_records')->insert(['currency' => null]);
    expect($currency->consistent())->toBeFalse();
    try {
        $currency->assertConsistent();
        $this->fail('Missing snapshot must be rejected.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['currency' => [Currency::ERROR]]);
    }
});
