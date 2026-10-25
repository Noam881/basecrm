<?php

namespace Awove\Finance;

use Awove\Core\Installation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class Currency
{
    public const ERROR = 'The installation currency differs from retained Finance records. Restore the agreed currency before entering financial changes or combining totals. Multi-currency conversion is unsupported.';

    public static function current(): string
    {
        return Installation::settings()['currency'];
    }

    public static function consistent(): bool
    {
        foreach (['finance_records', 'finance_payroll_preparations'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->where('currency', '<>', self::current())->exists()) {
                return false;
            }
        }

        return true;
    }

    public static function assertConsistent(): void
    {
        if (! self::consistent()) {
            throw ValidationException::withMessages(['currency' => self::ERROR]);
        }
    }
}
