<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Currency;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Money;
use Awove\Core\Services\Csv;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Tables\Query;
use Awove\Nonprofit\Fundraising\Models\Constituent;
use Awove\Nonprofit\Fundraising\Models\History;
use Awove\Nonprofit\Fundraising\Models\Pledge;
use Awove\Nonprofit\NonprofitModule;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class Controller
{
    private function options($u, ?Constituent $c = null): array
    {
        $u = Access::fresh($u);
        $owners = Access::owners($u, $c?->getAttribute('relationship_id'));
        $retained = Records::person($c?->owner_id);
        if ($retained && ! in_array($retained['id'], array_column($owners, 'id'), true)) {
            $owners[] = $retained;
        }
        $canClassify = $u->can(Access::PREFIX.'create') && $u->can('identities.classifications.manage');
        $companies = $canClassify ? IdentityAccess::identities($u, Company::class)->get(['id', 'name']) : collect();
        $companyIds = $companies->pluck('id')->all();
        $contacts = $canClassify ? IdentityAccess::identities($u, Contact::class)->get(['id', 'name', 'company_id'])->map(function (Contact $r) use ($companyIds) {
            $d = $r->only(['id', 'name', 'company_id']);
            if (! in_array($d['company_id'], $companyIds, true)) {
                $d['company_id'] = null;
            }

            return $d;
        })->all() : [];
        $relationships = Access::relationships($u)->whereNull('archived_at')->where('active', true)->orderBy('id')->get()->map(fn ($r) => app(Records::class)->relationship($u, $r->id))->all();

        return ['sections' => (new NonprofitModule)->sections($u), 'owners' => $owners, 'currentOwnerId' => $u->id, 'relationships' => $relationships, 'roles' => Access::ROLES, 'today' => Records::today(), 'currency' => Currency::current(), 'currencyError' => app(Currency::class)->consistent() ? null : Currency::ERROR, 'canCreate' => $u->can(Access::PREFIX.'create'), 'canAssign' => $u->can(Access::PREFIX.'assign'), 'canArchive' => $u->can(Access::PREFIX.'archive'), 'canExport' => $u->can(Access::PREFIX.'export'), 'canViewPledges' => $u->can(Access::PREFIX.'pledges.view'), 'canManagePledges' => $u->can(Access::PREFIX.'pledges.view') && $u->can(Access::PREFIX.'pledges.manage'), 'canViewReceipts' => $u->can(Access::PREFIX.'receipts.view'), 'canManageReceipts' => $u->can(Access::PREFIX.'receipts.view') && $u->can(Access::PREFIX.'receipts.manage'), 'canClassify' => $canClassify, 'contacts' => $contacts, 'companies' => $companies, 'classificationOwners' => $canClassify ? config('auth.providers.users.model')::where('active', true)->when(! $u->can('identities.classifications.assign') && ! $u->can('users.manage'), fn ($q) => $q->whereKey($u->id))->get(['id', 'name']) : [], 'canAssignClassification' => $u->can('identities.classifications.assign') || $u->can('users.manage')];
    }

    private function constituent($u, Constituent $c): array
    {
        return [...$c->toArray(), 'relationship_id' => $c->getAttribute('relationship_id'), 'relationship' => app(Records::class)->relationship($u, $c->getAttribute('relationship_id')), 'owner' => Records::person($c->owner_id)];
    }

    private function pledge($u, Pledge $p): array
    {
        Access::money($u);
        $c = Access::query($u)->findOrFail($p->constituent_id);

        return [...$p->toArray(), 'amount' => $p->getAttribute('amount'), 'currency' => $p->getAttribute('currency'), 'constituent' => $this->constituent($u, $c)];
    }

    private function history($u, Constituent $c, ?int $pledge = null): array
    {
        return History::where('constituent_id', $c->id)->when($pledge, fn ($q) => $q->where('pledge_id', $pledge))->when(! $u->can(Access::PREFIX.'pledges.view'), fn ($q) => $q->whereNull('pledge_id'))->when(! $u->can(Access::PREFIX.'receipts.view'), fn ($q) => $q->where('event', 'not like', 'receipt.%'))->orderByDesc('id')->get()->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'changed_fields' => $e->changed_fields, 'actor' => Records::person($e->actor_id), 'created_at' => $e->getAttribute('created_at')->toISOString()])->all();
    }

    private function filters(Request $r): string
    {
        abort_unless(Access::available($r->user()), 403);
        $data = $r->validate(['tab' => ['nullable', Rule::in(['constituents', 'pledges'])], 'role' => ['nullable', Rule::in(Access::ROLES)], 'owner_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['all', 'due', 'archived'])]]);
        $tab = $data['tab'] ?? 'constituents';
        if ($tab === 'pledges') {
            Access::money($r->user());
        }

        return $tab;
    }

    private function contexts(Request $r)
    {
        $q = Access::query($r->user());
        if ($r->filled('owner_id')) {
            $q->where('owner_id', $r->integer('owner_id'));
        }
        if ($r->filled('role')) {
            $q->whereIn('relationship_id', Access::relationships($r->user())->where('role', $r->input('role'))->select('id'));
        }

        return $q;
    }

    private function query(Request $r, string $tab)
    {
        $c = $this->contexts($r);
        if ($tab === 'constituents') {
            $q = $c;
            $date = 'next_action_on';
        } else {
            $q = Access::pledges($r->user())->whereIn('constituent_id', $c->whereNull('archived_at')->select('id'));
            $date = 'expected_on';
        }
        $r->input('view') === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        foreach (['from' => '>=', 'to' => '<='] as $f => $op) {
            if ($r->filled($f)) {
                $q->where($date, $op, $r->input($f));
            }
        }
        if ($r->input('view') === 'due') {
            $q->where($date, '<=', Records::today());
        }

        return $q;
    }

    public function index(Request $r)
    {
        $tab = $this->filters($r);
        $u = Access::fresh($r->user());
        $table = app(Query::class)->run($this->query($r, $tab), 'nonprofit.fundraising-'.$tab, $r);
        $table['page']->through(fn ($row) => $row instanceof Constituent ? [...$this->constituent($u, $row), 'record_url' => '/nonprofit/fundraising/constituents/'.$row->id, 'record_link_column' => 'name'] : [...$this->pledge($u, $row), 'record_url' => '/nonprofit/fundraising/pledges/'.$row->id, 'record_link_column' => 'reference']);
        $contexts = $this->contexts($r)->whereNull('archived_at');
        $report = ['constituents' => (clone $contexts)->count(), 'actions_due' => (clone $contexts)->whereNotNull('next_action')->where('next_action_on', '<=', Records::today())->count()];
        if ($u->can(Access::PREFIX.'pledges.view')) {
            $pledges = Access::pledges($u)->whereIn('constituent_id', (clone $contexts)->select('id'))->whereNull('archived_at');
            $expected = clone $pledges;
            foreach (['from' => '>=', 'to' => '<='] as $f => $op) {
                if ($r->filled($f)) {
                    $expected->where('expected_on', $op, $r->input($f));
                }
            }
            $report['pledged_amount'] = app(Currency::class)->consistent() ? Money::sum((clone $expected)->pluck('amount')->all()) : null;
            $adapter = app(Receipts::class);
            $actuals = [];
            $complete = $adapter->available($u) && app(Currency::class)->consistent();
            $seen = [];
            if ($complete) {
                foreach (Access::pledges($u)->whereIn('constituent_id', $this->contexts($r)->select('id'))->get() as $p) {
                    $summary = $adapter->forPledge($u, $p);
                    if (! $summary['available']) {
                        $complete = false;
                        break;
                    }
                    foreach ($summary['receipts'] as $link) {
                        if (isset($seen[$link['payment_id']])) {
                            continue;
                        }$seen[$link['payment_id']] = true;
                        if (($r->filled('from') && $link['paid_on'] < $r->input('from')) || ($r->filled('to') && $link['paid_on'] > $r->input('to'))) {
                            continue;
                        }$actuals[] = $link['amount'];
                    }
                }
            }
            $report['receipts_available'] = $complete;
            if ($complete) {
                $report['received_amount'] = Money::sum($actuals);
            }
        }

        return Inertia::render('Nonprofit/Fundraising/Index', [...$this->options($u), 'tab' => $tab, 'table' => $table, 'report' => $report, 'filters' => $r->only(['role', 'owner_id', 'from', 'to', 'view'])]);
    }

    public function store(Request $r, Records $s)
    {
        $c = $s->save($r->user(), $r->all());

        return redirect(Access::query($r->user())->whereKey($c->id)->exists() ? '/nonprofit/fundraising/constituents/'.$c->id : '/nonprofit/fundraising');
    }

    public function update(Request $r, int $id, Records $s)
    {
        $s->save($r->user(), $r->all(), $id);

        return Access::query($r->user())->whereKey($id)->exists() ? back() : redirect('/nonprofit/fundraising');
    }

    public function archive(Request $r, int $id, Records $s)
    {
        $s->archive($r->user(), $id, $r->all());

        return redirect('/nonprofit/fundraising');
    }

    public function show(Request $r, int $id)
    {
        $u = Access::fresh($r->user());
        $c = Access::query($u)->findOrFail($id);

        return Inertia::render('Nonprofit/Fundraising/Constituent', [...$this->options($u, $c), 'record' => $this->constituent($u, $c), 'editable' => Access::editable($u, $c), 'history' => $this->history($u, $c), 'pledges' => $u->can(Access::PREFIX.'pledges.view') ? Access::pledges($u)->where('constituent_id', $id)->orderByDesc('id')->get()->map(fn ($p) => $this->pledge($u, $p))->all() : []]);
    }

    public function storePledge(Request $r, int $id, Records $s)
    {
        $p = $s->pledge($r->user(), $id, $r->all());

        return redirect('/nonprofit/fundraising/pledges/'.$p->id);
    }

    public function updatePledge(Request $r, int $id, Records $s)
    {
        $p = Access::pledges($r->user())->findOrFail($id);
        $s->pledge($r->user(), $p->constituent_id, $r->all(), $id);

        return back();
    }

    public function archivePledge(Request $r, int $id, Records $s)
    {
        $s->archivePledge($r->user(), $id, $r->all());

        return back();
    }

    public function showPledge(Request $r, int $id, Receipts $receipts)
    {
        $u = Access::fresh($r->user());
        $p = Access::pledges($u)->findOrFail($id);
        $c = Access::query($u)->findOrFail($p->constituent_id);
        $editable = Access::editable($u, $c) && $p->archived_at === null;

        return Inertia::render('Nonprofit/Fundraising/Pledge', [...$this->options($u, $c), 'record' => $this->pledge($u, $p), 'editable' => $editable, 'history' => $this->history($u, $c, $id), ...($u->can(Access::PREFIX.'receipts.view') ? ['actuals' => $receipts->forPledge($u, $p), 'receiptOptions' => $editable && $u->can(Access::PREFIX.'receipts.manage') ? $receipts->options($u, $p) : []] : [])]);
    }

    public function link(Request $r, int $id, Receipts $s)
    {
        $s->link($r->user(), $id, $r->all());

        return back();
    }

    public function unlink(Request $r, int $id, Receipts $s)
    {
        $s->archive($r->user(), $id, $r->all());

        return back();
    }

    public function classify(Request $r, IdentityRelationships $s)
    {
        $u = Access::fresh($r->user());
        abort_unless(Access::available($u) && $u->can(Access::PREFIX.'create'), 403);
        $data = $r->validate(['role' => ['required', Rule::in(Access::ROLES)]]);
        $s->create($u, [...$r->only(['contact_id', 'company_id', 'responsible_id', 'main_contact_id', 'active', 'notes']), 'role' => $data['role']]);

        return back();
    }

    public function export(Request $r, Csv $csv)
    {
        $tab = $this->filters($r);
        $u = Access::fresh($r->user());
        abort_unless($u->can(Access::PREFIX.'export'), 403);
        $q = app(Query::class)->filtered($this->query($r, $tab), 'nonprofit.fundraising-'.$tab, $r);
        abort_if((clone $q)->count() > 5000, 422, __('Narrow the filters to at most 5,000 records.'));
        $columns = (new Tables)->columns('nonprofit.fundraising-'.$tab, $u);
        $rows = (function () use ($q, $columns) {
            foreach ($q->cursor() as $row) {
                yield array_map(fn ($i) => $row->getAttribute('dt_'.$i) ?? '', array_keys($columns));
            }
        })();

        return $csv->download('fundraising-'.$tab.'.csv', array_map(fn ($c) => $c->label, $columns), $rows);
    }
}
