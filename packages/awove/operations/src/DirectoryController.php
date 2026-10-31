<?php

namespace Awove\Operations;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Modules;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Services\WorkItems;
use Awove\Core\Tables\Query;
use Awove\Operations\Models\RequestDetails;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class DirectoryController
{
    public function index(Request $request, Context $context, IdentityRelationships $relationships)
    {
        $filters = $request->validate(['role' => ['nullable', Rule::in(['customer', 'supplier'])], 'active' => ['nullable', Rule::in(['1', '0'])], 'archived' => ['nullable', Rule::in(['1'])]]);
        abort_unless($request->user()->can('identities.classifications.view') || $request->user()->can('identities.classifications.manage'), 403);
        $table = app(Query::class)->run($this->query($request), 'operations.directory', $request);
        $table['page']->through(fn ($record) => [...$relationships->describe($request->user(), $record), 'record_url' => '/operations/directory/'.$record->id, 'record_link_column' => 'name', 'cells' => $record->getAttribute('cells')]);

        return Inertia::render('Operations/Directory', [...$context->options($request->user()), ...$this->options($request->user()), 'table' => $table, 'filters' => $filters]);
    }

    public function store(Request $request, IdentityRelationships $relationships)
    {
        $request->validate(['role' => ['required', Rule::in(['customer', 'supplier'])]]);
        $record = $relationships->create($request->user(), $request->only(['contact_id', 'company_id', 'role', 'responsible_id', 'main_contact_id', 'active', 'notes']));

        return redirect('/operations/directory/'.$record->id);
    }

    public function show(Request $request, int $id, Context $context, IdentityRelationships $relationships)
    {
        $record = $relationships->query($request->user(), null, true)->whereIn('role', ['customer', 'supplier'])->findOrFail($id);
        $data = $relationships->describe($request->user(), $record);
        $requests = app(WorkItems::class)->query($request->user(), 'operations', 'requests')->whereNull('archived_at')->whereIn('id', RequestDetails::where('customer_relationship_id', $id)->select('work_item_id'))->get(['id', 'title']);
        $orders = Access::orders($request->user())->whereNull('archived_at')->where('supplier_relationship_id', $id)->get(['id', 'reference']);
        $leads = [];
        if (app(Modules::class)->enabled('sales')) {
            $leads = IdentityAccess::leads($request->user())->whereNull('archived_at')->where($record->contact_id ? 'contact_id' : 'company_id', $record->contact_id ?? $record->company_id)->get(['id', 'title']);
        }

        return Inertia::render('Operations/Classification', [...$context->options($request->user()), ...$this->options($request->user()), 'record' => $data, 'history' => $relationships->history($request->user(), $record), 'related' => ['requests' => $requests, 'orders' => $orders, 'opportunities' => $leads]]);
    }

    public function update(Request $request, int $id, IdentityRelationships $relationships)
    {
        $record = $relationships->query($request->user(), null, true)->whereIn('role', ['customer', 'supplier'])->findOrFail($id);
        $relationships->update($request->user(), $record->id, $request->only(['version', 'responsible_id', 'main_contact_id', 'active', 'notes']));

        return back();
    }

    public function archive(Request $request, int $id, IdentityRelationships $relationships)
    {
        $record = $relationships->query($request->user(), null, true)->whereIn('role', ['customer', 'supplier'])->findOrFail($id);
        $relationships->archive($request->user(), $record->id, $request->only(['version', 'archived']));

        return back();
    }

    public function export(Request $request, Context $context)
    {
        return $context->export($request, $this->query($request), 'operations.directory', 'customers-suppliers.csv');
    }

    private function query(Request $request)
    {
        abort_unless($request->user()->can('identities.classifications.view') || $request->user()->can('identities.classifications.manage'), 403);
        $request->validate(['role' => ['nullable', Rule::in(['customer', 'supplier'])], 'active' => ['nullable', Rule::in(['1', '0'])], 'archived' => ['nullable', Rule::in(['1'])]]);
        $query = app(IdentityRelationships::class)->query($request->user(), $request->input('role'), $request->input('archived') === '1')->whereIn('role', ['customer', 'supplier']);
        if ($request->input('archived') === '1') {
            $query->whereNotNull('archived_at');
        }
        if ($request->filled('active')) {
            $query->where('active', $request->input('active') === '1');
        }

        return $query;
    }

    private function options($user): array
    {
        $companies = IdentityAccess::identities($user, Company::class)->orderBy('name')->get(['id', 'name']);
        $visibleCompanyIds = $companies->modelKeys();
        $contacts = IdentityAccess::identities($user, Contact::class)->orderBy('name')->get(['id', 'name', 'company_id'])->map(fn (Contact $contact): array => ['id' => $contact->getKey(), 'name' => $contact->getAttribute('name'), 'company_id' => in_array($contact->getAttribute('company_id'), $visibleCompanyIds, true) ? $contact->getAttribute('company_id') : null]);

        return ['contacts' => $contacts, 'companies' => $companies, 'classificationOwners' => config('auth.providers.users.model')::where('active', true)->get(['id', 'name']), 'canAssignClassification' => $user->can('identities.classifications.assign') || $user->can('users.manage')];
    }
}
