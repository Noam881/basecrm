<?php

namespace Awove\Finance;

use App\Models\User;
use Awove\Core\Access as IdentityAccess;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Tables\Catalog;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class LedgerController
{
    private function cap(Request $request): string
    {
        return $request->route('cap');
    }

    public function index(Request $request, Context $context, Ledger $ledger)
    {
        $cap = $this->cap($request);
        $filters = $context->filters($request, $cap);
        $table = app(Query::class)->run($this->query($request, $context, $ledger), 'finance.'.$cap, $request);
        $table['page']->through(fn ($record) => [...$ledger->describe($request->user(), $cap, $record), 'record_url' => '/finance/'.$cap.'/'.$record->id, 'record_link_column' => 'reference']);

        return Inertia::render('Finance/Ledger', [...$context->options($request->user(), $cap), ...$this->intakeOptions($request->user(), $cap), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request, Ledger $ledger)
    {
        $cap = $this->cap($request);
        $record = $ledger->create($request->user(), $cap, $request->all());

        return redirect('/finance/'.$cap.'/'.$record->id);
    }

    public function show(Request $request, int $id, Context $context, Ledger $ledger)
    {
        $cap = $this->cap($request);
        $record = $ledger->find($request->user(), $cap, $id);

        return Inertia::render('Finance/Record', [...$context->options($request->user(), $cap), ...$this->intakeOptions($request->user(), $cap), 'record' => $ledger->describe($request->user(), $cap, $record), 'payments' => $ledger->payments($request->user(), $cap, $record), 'history' => $ledger->history($request->user(), $cap, $record)]);
    }

    public function update(Request $request, int $id, Ledger $ledger)
    {
        $ledger->update($request->user(), $this->cap($request), $id, $request->all());

        return back();
    }

    public function payment(Request $request, int $id, Ledger $ledger)
    {
        $ledger->payment($request->user(), $this->cap($request), $id, $request->all());

        return back();
    }

    public function cancel(Request $request, int $id, Ledger $ledger)
    {
        $ledger->cancel($request->user(), $this->cap($request), $id, $request->all());

        return back();
    }

    public function document(Request $request, int $id, Ledger $ledger)
    {
        $record = $ledger->find($request->user(), $this->cap($request), $id);
        abort_unless($record->document_url !== null && $record->document_url !== '', 404);

        return app(DocumentLinks::class)->redirect($record->document_url);
    }

    public function classify(Request $request, IdentityRelationships $relationships)
    {
        $cap = $this->cap($request);
        $user = Access::actor($request->user(), $cap);
        abort_unless($user->can('finance.'.$cap.'.create'), 403);
        $input = $request->only(['contact_id', 'company_id', 'responsible_id', 'main_contact_id', 'active', 'notes']);
        $relationships->create($user, [...$input, 'role' => Ledger::role($cap)]);

        return back();
    }

    public function export(Request $request, Context $context, Ledger $ledger)
    {
        $cap = $this->cap($request);
        $user = Access::actor($request->user(), $cap);
        abort_unless($user->can('finance.'.$cap.'.export'), 403);
        $context->filters($request, $cap);
        $columns = app(Catalog::class)->columns('finance.'.$cap, $user);
        $query = app(Query::class)->filtered($this->query($request, $context, $ledger), 'finance.'.$cap, $request);
        if ((clone $query)->count() > 5000) {
            throw ValidationException::withMessages(['export' => __('Export at most 5,000 filtered records.')]);
        }$rows = (function () use ($query, $columns) {
            foreach ($query->cursor() as $record) {
                $row = [];
                foreach (array_values($columns) as $index => $column) {
                    $value = $record->getAttribute('dt_'.$index);
                    foreach ($column->options as $option) {
                        if ((string) $option['value'] === (string) $value) {
                            $value = $option['label'];
                            break;
                        }
                    }$row[] = $value ?? '';
                }yield $row;
            }
        })();

        return app(Csv::class)->download($cap.'.csv', array_map(fn ($c) => $c->label, array_values($columns)), $rows);
    }

    public function query(Request $request, Context $context, Ledger $ledger)
    {
        $cap = $this->cap($request);
        $query = $ledger->query($request->user(), $cap);
        if ($request->filled('owner_id')) {
            $query->where('owner_id', $request->integer('owner_id'));
        }if ($request->filled('relationship_id')) {
            $query->where('relationship_id', $request->integer('relationship_id'));
        }if ($request->filled('from')) {
            $query->where('due_on', '>=', $request->input('from'));
        }if ($request->filled('to')) {
            $query->where('due_on', '<=', $request->input('to'));
        }$view = $request->input('view');
        if (in_array($view, ['overdue', 'outstanding'])) {
            $query->whereNull('cancelled_at')->whereRaw(Tables::remainingExpression().'>0');
        }if ($view === 'overdue') {
            $query->where('due_on', '<', now(Installation::settings()['timezone'])->toDateString());
        }if ($view === 'cancelled') {
            $query->whereNotNull('cancelled_at');
        }if ($view === 'paid') {
            $query->whereNull('cancelled_at')->whereRaw(Tables::remainingExpression().'=0');
        }if ($view === 'unassigned') {
            $query->whereNull('owner_id');
        }

        return $query;
    }

    private function intakeOptions($user, string $cap): array
    {
        $companies = IdentityAccess::identities($user, Company::class)->get(['id', 'name']);
        $ids = $companies->modelKeys();
        $contacts = IdentityAccess::identities($user, Contact::class)->get(['id', 'name', 'company_id'])->map(fn (Contact $c): array => ['id' => $c->getKey(), 'name' => $c->getAttribute('name'), 'company_id' => in_array($c->getAttribute('company_id'), $ids, true) ? $c->getAttribute('company_id') : null]);

        return ['relationships' => app(Context::class)->relationships($user, $cap), 'contacts' => $contacts, 'companies' => $companies, 'classificationOwners' => User::where('active', true)->get(['id', 'name']), 'canAssignClassification' => $user->can('identities.classifications.assign') || $user->can('users.manage')];
    }
}
