<?php

namespace Awove\Finance;

use Awove\Core\Installation;
use Awove\Core\Money;
use Awove\Finance\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;

class OverviewController
{
    public function __invoke(Request $request, Ledger $ledger)
    {
        $filters = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'owner_id' => 'nullable|integer|min:1']);
        $metrics = [];
        foreach (['collections', 'expenses'] as $cap) {
            if (! Access::available($request->user(), $cap)) {
                continue;
            }$records = $ledger->query($request->user(), $cap);
            if ($request->filled('owner_id')) {
                $records->where('owner_id', $request->integer('owner_id'));
            }$expected = (clone $records)->whereNull('cancelled_at')->whereRaw(Tables::remainingExpression().'>0');
            if ($request->filled('from')) {
                $expected->where('due_on', '>=', $request->input('from'));
            }if ($request->filled('to')) {
                $expected->where('due_on', '<=', $request->input('to'));
            }
            $actual = Payment::whereIn('record_id', (clone $records)->select('finance_records.id'));
            if ($request->filled('from')) {
                $actual->where('paid_on', '>=', $request->input('from'));
            }if ($request->filled('to')) {
                $actual->where('paid_on', '<=', $request->input('to'));
            }
            $overdue = (clone $expected)->where('due_on', '<', now(Installation::settings()['timezone'])->toDateString());
            $currencies = (clone $records)->distinct()->pluck('currency')->all();
            $consistent = Currency::consistent();
            $metrics[$cap] = ['expected' => $consistent ? Money::sum([(clone $expected)->selectRaw('COALESCE(SUM('.Tables::remainingExpression().'),0)::text AS expected')->value('expected')]) : null, 'actual' => $consistent ? Money::sum([(clone $actual)->selectRaw('COALESCE(SUM(amount),0)::text AS actual')->value('actual')]) : null, 'overdue_count' => $overdue->count(), 'currency_mismatch' => ! $consistent];
        }

        return Inertia::render('Finance/Overview', ['sections' => app(FinanceModule::class)->sections($request->user()), 'metrics' => $metrics, 'filters' => $filters, 'currency' => Currency::current(), 'currencyError' => Currency::consistent() ? null : Currency::ERROR]);
    }
}
