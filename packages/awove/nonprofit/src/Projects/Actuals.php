<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Currency;
use Awove\Core\Money;
use Awove\Nonprofit\Projects\Models\Budget;
use Awove\Nonprofit\Projects\Models\PaymentLink;
use Awove\Nonprofit\Projects\Models\Project;

class Actuals
{
    public function forProject($u, Project $p, ?string $from = null, ?string $to = null): array
    {
        Access::find($u, $p->id);
        $u = $u->fresh();
        $visible = $u->can('nonprofit.projects.budgets.view');
        $budget = $visible ? Budget::where('project_id', $p->id)->latest('id')->first() : null;
        $result = ['planned_budget' => $budget?->amount, 'currency' => $budget->currency ?? Currency::current(), 'from' => $from, 'to' => $to];
        foreach (['incoming', 'outgoing'] as $direction) {
            $available = $visible && app(Connections::class)->financeAvailable($u, $direction) && app(Currency::class)->consistent();
            $amounts = [];
            if ($available) {
                foreach (PaymentLink::where('project_id', $p->id)->where('direction', $direction)->whereNull('archived_at')->get() as $link) {
                    $payment = app(Connections::class)->payment($u, $direction, $link->payment_id);
                    if (! $payment || $payment['currency'] !== ($budget->currency ?? Currency::current())) {
                        $available = false;
                        break;
                    }if (($from && $payment['paid_on'] < $from) || ($to && $payment['paid_on'] > $to)) {
                        continue;
                    }$amounts[] = $payment['amount'];
                }
            }
            $result[$direction] = ['available' => $available, 'total' => $available ? Money::sum($amounts) : null];
        }

        return $result;
    }
}
