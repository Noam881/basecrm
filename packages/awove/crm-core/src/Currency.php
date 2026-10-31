<?php

namespace Awove\Core;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Installed modules register retained monetary snapshots, including disabled capabilities. */
class Currency
{
    public const ERROR = 'The installation currency differs from retained monetary records. Restore the agreed currency before entering monetary changes or combining totals. Multi-currency conversion is unsupported.';

    /** @var array<string, list<string>> */
    private array $tables = [];

    /** @param list<string> $tables Trusted module-owned tables containing a currency snapshot column. */
    public function register(string $module, array $tables): void
    {
        if (! preg_match('/^[a-z][a-z0-9-]*$/D', $module) || $tables === []) {
            throw new InvalidArgumentException('Currency registrations require a module key and a list of owned tables.');
        }
        foreach ($tables as $table) {
            if (! preg_match('/^[a-z][a-z0-9_]*$/D', $table) || ! str_starts_with($table, str_replace('-', '_', $module).'_')) {
                throw new InvalidArgumentException('Currency tables must use their module-owned identifier prefix.');
            }
        }
        $this->tables[$module] = array_values(array_unique([...($this->tables[$module] ?? []), ...$tables]));
    }

    public static function current(): string
    {
        return Installation::settings()['currency'];
    }

    public function consistent(): bool
    {
        $currency = self::current();
        foreach ($this->tables as $tables) {
            foreach ($tables as $table) {
                if (Schema::hasTable($table) && DB::table($table)->where(function ($query) use ($currency) {
                    $query->whereNull('currency')->orWhere('currency', '<>', $currency);
                })->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

    public function assertConsistent(): void
    {
        if (! $this->consistent()) {
            throw ValidationException::withMessages(['currency' => self::ERROR]);
        }
    }
}
