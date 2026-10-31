<?php

namespace Awove\Finance\Contracts;

use Awove\Core\Contracts\DashboardContributor;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Contracts\ReminderContributor;

interface FinanceCapability extends DashboardContributor, RecordTableContributor, ReminderContributor
{
    public function key(): string;

    public function permissions(): array;

    public function navigation(): array;
}
