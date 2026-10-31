<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Modules;
use Awove\Finance\Ledger;
use Awove\Finance\Models\Payment;

class Connections
{
    private function actor($u)
    {
        $u = $u?->fresh();

        return Access::available($u) ? $u : null;
    }

    public function resourceAvailable($u, string $type): bool
    {
        $u = $this->actor($u);
        if (! $u) {
            return false;
        }

        return match ($type) {
            'asset' => class_exists(\Awove\Assets\Access::class) && app(Modules::class)->enabled('assets') && \Awove\Assets\Access::available($u),'operations-order' => class_exists(\Awove\Operations\Access::class) && app(Modules::class)->enabled('operations') && \Awove\Operations\Access::available($u),default => false
        };
    }

    public function resourceQuery($u, string $type)
    {
        $u = $this->actor($u);
        if (! $this->resourceAvailable($u, $type)) {
            return null;
        }

        return $type === 'asset' ? \Awove\Assets\Access::query($u) : \Awove\Operations\Access::orders($u);
    }

    public function resource($u, string $type, int $id, bool $activeOnly = false): ?array
    {
        $u = $this->actor($u);
        if (! $u) {
            return null;
        }
        $q = $this->resourceQuery($u, $type);
        if (! $q) {
            return null;
        }if ($activeOnly) {
            $q->whereNull('archived_at');
        }$r = $q->find($id);
        if (! $r) {
            return null;
        }

        return ['id' => $r->id, 'type' => $type, 'label' => $type === 'asset' ? $r->getAttribute('code').' · '.$r->getAttribute('name') : $r->getAttribute('reference').' · '.$r->getAttribute('description'), 'href' => $type === 'asset' ? '/assets/register/'.$r->id : '/operations/orders/'.$r->id];
    }

    public function resourceOptions($u): array
    {
        $u = $this->actor($u);
        $result = [];
        foreach (['asset', 'operations-order'] as $type) {
            $q = $this->resourceQuery($u, $type);
            $result[$type] = ['available' => (bool) $q, 'items' => $q ? $q->whereNull('archived_at')->orderBy('id')->get()->map(fn ($r) => $this->resource($u, $type, $r->id))->filter()->values()->all() : []];
        }

        return $result;
    }

    public static function cap(string $direction): string
    {
        return match ($direction) {
            'incoming' => 'collections','outgoing' => 'expenses',default => abort(404)
        };
    }

    public function financeAvailable($u, string $direction): bool
    {
        $u = $this->actor($u);

        return $u && $u->can('nonprofit.projects.budgets.view') && class_exists(Ledger::class) && \Awove\Finance\Access::available($u, self::cap($direction));
    }

    public function paymentQuery($u, string $direction)
    {
        $u = $this->actor($u);
        if (! $this->financeAvailable($u, $direction)) {
            return null;
        }

        return Payment::query()->whereIn('record_id', app(Ledger::class)->query($u, self::cap($direction))->select('finance_records.id'));
    }

    public function payment($u, string $direction, int $id): ?array
    {
        $u = $this->actor($u);
        if (! $u) {
            return null;
        }
        $payment = $this->paymentQuery($u, $direction)?->find($id);
        if (! $payment) {
            return null;
        }$record = app(Ledger::class)->find($u, self::cap($direction), $payment->record_id);

        return ['id' => $payment->id, 'direction' => $direction, 'label' => $record->reference.' · '.$payment->paid_on->toDateString().' · '.$payment->amount.' '.$record->currency, 'amount' => $payment->amount, 'currency' => $record->currency, 'paid_on' => $payment->paid_on->toDateString(), 'href' => '/finance/'.self::cap($direction).'/'.$record->id];
    }

    public function paymentOptions($u): array
    {
        $u = $this->actor($u);
        $result = [];
        foreach (['incoming', 'outgoing'] as $d) {
            $q = $this->paymentQuery($u, $d);
            $result[$d] = ['available' => (bool) $q, 'items' => $q ? $q->whereNotIn('id', Models\PaymentLink::whereNull('archived_at')->select('payment_id'))->orderByDesc('paid_on')->get()->map(fn ($p) => $this->payment($u, $d, $p->id))->filter()->values()->all() : []];
        }

        return $result;
    }
}
