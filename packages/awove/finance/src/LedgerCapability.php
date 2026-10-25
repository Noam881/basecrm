<?php

namespace Awove\Finance;

use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Installation;
use Awove\Finance\Contracts\FinanceCapability;

class LedgerCapability implements FinanceCapability
{
    public function __construct(private string $cap)
    {
        Ledger::kind($cap);
    }

    public function key(): string
    {
        return $this->cap;
    }

    public function permissions(): array
    {
        return array_map(fn ($p) => 'finance.'.$this->cap.'.'.$p, ['view.own', 'view.all', 'create', 'edit.own', 'edit.all', 'assign', 'export', 'record-payment', 'cancel']);
    }

    public function navigation(): array
    {
        return [new NavigationItem($this->cap === 'collections' ? 'Collections' : 'Supplier expenses', '/finance/'.$this->cap, 'finance.'.$this->cap.'.view.own', ['finance.'.$this->cap.'.view.all'])];
    }

    public function tableIds(): array
    {
        return ['finance.'.$this->cap];
    }

    public function tableColumns(string $table, $user): array
    {
        return Access::available($user, $this->cap) ? (new Tables)->columns($user, $this->cap) : [];
    }

    public function tableDefaults(string $table): array
    {
        return ['relationship', 'reference', ...($this->cap === 'expenses' ? ['category'] : []), 'total', 'paid_amount', 'remaining_amount', 'due_on', 'status', 'owner'];
    }

    public function widgets($user): array
    {
        if (! Access::available($user, $this->cap)) {
            return [];
        }$query = app(Ledger::class)->query($user, $this->cap)->whereNull('cancelled_at')->whereRaw(Tables::remainingExpression().'>0');
        $overdue = (clone $query)->where('due_on', '<', now(Installation::settings()['timezone'])->toDateString())->count();

        return [['id' => 'finance.'.$this->cap.'.overdue', 'label' => $this->cap === 'collections' ? 'Overdue customer charges' : 'Overdue supplier expenses', 'value' => $overdue, 'href' => '/finance/'.$this->cap.'?view=overdue', 'permission' => 'finance.'.$this->cap.'.view.own', 'anyPermissions' => ['finance.'.$this->cap.'.view.all']]];
    }

    public function reminderSources(): array
    {
        return [new LedgerReminders($this->cap)];
    }
}
