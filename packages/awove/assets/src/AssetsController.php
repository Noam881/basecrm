<?php

namespace Awove\Assets;

use Awove\Assets\Models\Asset;
use Awove\Assets\Models\AssetDocument;
use Awove\Assets\Models\AssetHistory;
use Awove\Assets\Models\Assignment;
use Awove\Assets\Models\MaintenanceCompletion;
use Awove\Assets\Models\MaintenanceCost;
use Awove\Assets\Models\MaintenanceRecord;
use Awove\Core\Access as CoreAccess;
use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\EmployeeReferences;
use Awove\Core\Services\IdentityRelationships;
use Awove\Core\Tables\Query;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssetsController
{
    private function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id, ['id', 'name'])?->only(['id', 'name']) : null;
    }

    private function options($u, ?Asset $asset = null, ?MaintenanceRecord $maintenance = null): array
    {
        $u = Access::fresh($u);
        $owners = Access::owners($u);
        $maintenanceOwners = Access::owners($u, $asset, true);
        $selectedOwner = $this->person($asset?->owner_id);
        if ($selectedOwner && ! in_array($selectedOwner['id'], array_column($owners, 'id'), true)) {
            $owners[] = $selectedOwner;
        }
        $selectedMaintenanceOwner = $this->person($maintenance?->owner_id);
        if ($selectedMaintenanceOwner && ! in_array($selectedMaintenanceOwner['id'], array_column($maintenanceOwners, 'id'), true)) {
            $maintenanceOwners[] = $selectedMaintenanceOwner;
        }
        $canClassify = $u->can('identities.classifications.manage') && ($u->can('assets.create') || $u->can('assets.assign'));
        $canAssignClassification = $u->can('identities.classifications.assign') || $u->can('users.manage');
        $classificationOwners = $canClassify ? config('auth.providers.users.model')::where('active', true)->when(! $canAssignClassification, fn ($q) => $q->whereKey($u->id))->orderBy('name')->get(['id', 'name']) : [];
        $ownerIds = Access::query($u)->distinct()->pluck('owner_id');
        $companies = $canClassify ? CoreAccess::identities($u, Company::class)->orderBy('name')->get(['id', 'name']) : collect();
        $companyIds = $companies->pluck('id')->all();
        $contacts = $canClassify ? CoreAccess::identities($u, Contact::class)->orderBy('name')->get(['id', 'name', 'company_id'])->map(function (Contact $contact) use ($companyIds) {
            $summary = $contact->only(['id', 'name', 'company_id']);
            if (! in_array($summary['company_id'], $companyIds, true)) {
                $summary['company_id'] = null;
            }

            return $summary;
        })->all() : [];

        return ['moduleLabel' => Records::moduleLabel(), 'sections' => ['register', 'assignments', 'maintenance'], 'owners' => $owners, 'maintenanceOwners' => $maintenanceOwners, 'filterOwners' => config('auth.providers.users.model')::whereIn('id', $ownerIds)->orderBy('name')->get(['id', 'name']), 'currentOwnerId' => $u->id, 'employees' => app(EmployeeReferences::class)->options($u), 'customers' => Relations::options($u, 'customer'), 'suppliers' => Relations::options($u, 'supplier'), 'statuses' => Records::statuses(), 'maintenanceStatuses' => Records::maintenanceStatuses(), 'today' => Records::today(), 'currency' => Currency::current(), 'currencyError' => app(Currency::class)->consistent() ? null : Currency::ERROR, 'canCreate' => $u->can('assets.create'), 'canAssign' => $u->can('assets.assign'), 'canArchive' => $u->can('assets.archive'), 'canExport' => $u->can('assets.export'), 'canViewDocuments' => $u->can('assets.documents.view'), 'canManageDocuments' => $u->can('assets.documents.view') && $u->can('assets.documents.manage'), 'canViewCosts' => $u->can('assets.costs.view'), 'canManageCosts' => $u->can('assets.costs.view') && $u->can('assets.costs.manage'), 'canManageEmployees' => $u->can('employees.references.view') && $u->can('employees.references.manage'), 'canClassify' => $canClassify, 'canAssignClassification' => $canAssignClassification, 'classificationOwners' => $classificationOwners, 'contacts' => $contacts, 'companies' => $companies];
    }

    private function serializeAsset(Asset $asset): array
    {
        return [...$asset->toArray(), 'owner' => $this->person($asset->owner_id)];
    }

    private function serializeAssignment($u, Assignment $row): array
    {
        $employee = app(EmployeeReferences::class)->summary($u, $row->getAttribute('employee_reference_id'));
        $customer = Relations::summary($u, $row->getAttribute('customer_relationship_id'), 'customer');
        $asset = Access::query($u)->findOrFail($row->asset_id);

        return [...$row->toArray(), 'asset' => ['id' => $asset->id, 'code' => $asset->code, 'name' => $asset->name, 'version' => $asset->version, 'status' => $asset->status], 'employee_reference_id' => $employee['id'] ?? null, 'employee' => $employee, 'employee_restricted' => $row->getAttribute('employee_reference_id') !== null && $employee === null, 'customer_relationship_id' => $customer['id'] ?? null, 'customer' => $customer, 'customer_restricted' => $row->getAttribute('customer_relationship_id') !== null && $customer === null, 'actor' => $this->person($row->created_by), 'returned_by' => $this->person($row->getAttribute('returned_by'))];
    }

    private function serializeMaintenance($u, MaintenanceRecord $row): array
    {
        $supplier = Relations::summary($u, $row->getAttribute('supplier_relationship_id'), 'supplier');
        $asset = Access::query($u)->findOrFail($row->asset_id);
        $data = [...$row->toArray(), 'owner' => $this->person($row->owner_id), 'supplier_relationship_id' => $supplier['id'] ?? null, 'supplier' => $supplier, 'supplier_restricted' => $row->getAttribute('supplier_relationship_id') !== null && $supplier === null, 'asset' => ['id' => $asset->id, 'code' => $asset->code, 'name' => $asset->name, 'version' => $asset->version, 'status' => $asset->status]];
        if (Access::fresh($u)->can('assets.costs.view')) {
            $cost = MaintenanceCost::where('maintenance_id', $row->id)->latest('id')->first();
            $data['cost'] = $cost?->amount;
            $data['currency'] = $cost?->currency;
        }

        return $data;
    }

    private function history($u, Asset $asset, ?int $maintenance = null): array
    {
        $u = Access::fresh($u);

        return AssetHistory::where('asset_id', $asset->id)->when($maintenance, fn ($q) => $q->where('maintenance_id', $maintenance))->orderByDesc('id')->get()->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'changed_fields' => array_values(array_filter($e->changed_fields, fn ($f) => ($f !== 'cost' || $u->can('assets.costs.view')) && ($f !== 'documents' || $u->can('assets.documents.view')))), 'actor' => $this->person($e->actor_id), 'created_at' => $e->getAttribute('created_at')->toISOString()])->all();
    }

    private function filters(Request $r): string
    {
        abort_unless(Access::available($r->user()), 403);
        $s = $r->input('section', 'register');
        $views = $s === 'register' ? ['archived'] : ($s === 'assignments' ? ['open', 'returned', 'upcoming', 'overdue', 'archived'] : ['open', 'upcoming', 'overdue', 'archived']);
        $r->validate(['section' => ['nullable', Rule::in(['register', 'assignments', 'maintenance'])], 'type' => 'nullable|string|max:120', 'location' => 'nullable|string|max:180', 'owner_id' => 'nullable|integer|min:1', 'status' => ['nullable', Rule::in(array_keys($s === 'maintenance' ? Records::maintenanceStatuses() : Records::statuses()))], 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['all', ...$views])]]);

        return $s;
    }

    private function assetFilters(Request $r, bool $status = false)
    {
        $q = Access::query($r->user());
        foreach (['type', 'location'] as $f) {
            if ($r->filled($f)) {
                $q->where($f, 'ilike', '%'.addcslashes($r->input($f), '%_\\').'%');
            }
        }
        if ($r->filled('owner_id')) {
            $q->where('owner_id', $r->integer('owner_id'));
        }
        if ($status && $r->filled('status')) {
            $q->where('status', $r->input('status'));
        }

        return $q;
    }

    private function query(Request $r, string $s)
    {
        $assets = $this->assetFilters($r, $s !== 'maintenance');
        if ($s === 'register') {
            $q = $assets;
            $r->input('view') === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
            $date = 'purchased_on';
        } else {
            $q = $s === 'assignments' ? Access::assignments($r->user()) : Access::maintenance($r->user());
            if ($r->input('view') === 'archived') {
                if ($s === 'assignments') {
                    $assets->whereNotNull('archived_at');
                } else {
                    $q->where(fn ($q) => $q->whereNotNull('archived_at')->orWhereIn('asset_id', Access::query($r->user())->whereNotNull('archived_at')->select('id')));
                }
            } else {
                $assets->whereNull('archived_at');
                if ($s === 'maintenance') {
                    $q->whereNull('archived_at');
                }
            }
            $q->whereIn('asset_id', $assets->select('id'));
            if ($s === 'maintenance' && $r->filled('status')) {
                $q->where('status', $r->input('status'));
            }
            $date = $s === 'assignments' ? 'assigned_on' : "(CASE WHEN assets_maintenance.status='completed' THEN assets_maintenance.next_due_on ELSE assets_maintenance.due_on END)";
            if ($r->input('view') === 'open') {
                $s === 'assignments' ? $q->whereNull('returned_on') : $q->where('status', '!=', 'completed');
            }
            if ($s === 'assignments' && $r->input('view') === 'returned') {
                $q->whereNotNull('returned_on');
            }
            if (in_array($r->input('view'), ['upcoming', 'overdue'], true)) {
                $q->whereIn('asset_id', Access::query($r->user())->where('status', '!=', 'retired')->select('id'));
                $deadline = $s === 'assignments' ? 'expected_return_on' : $date;
                if ($s === 'assignments') {
                    $q->whereNull('returned_on');
                }
                if ($r->input('view') === 'overdue') {
                    $q->whereRaw($deadline.' < ?', [Records::today()]);
                } else {
                    $q->whereRaw($deadline.' BETWEEN ? AND ?', [Records::today(), now(Installation::settings()['timezone'])->addDays(30)->toDateString()]);
                }
            }
        }
        foreach (['from' => '>=', 'to' => '<='] as $f => $op) {
            if ($r->filled($f)) {
                $q->whereRaw($date.' '.$op.' ?', [$r->input($f)]);
            }
        }

        return $q;
    }

    public function index(Request $r)
    {
        $s = $this->filters($r);
        $assets = $this->assetFilters($r, $s === 'register')->whereNull('archived_at');
        $metrics = [];
        foreach (Records::statuses() as $id => $label) {
            $metrics[] = ['id' => 'status-'.$id, 'label' => $label, 'value' => (clone $assets)->where('status', $id)->count(), 'href' => '/assets?'.http_build_query([...$r->only(['type', 'location', 'owner_id']), 'section' => 'register', 'status' => $id])];
        }
        $active = $this->assetFilters($r)->whereNull('archived_at')->where('status', '!=', 'retired')->select('id');
        $today = Records::today();
        $through = now(Installation::settings()['timezone'])->addDays(30)->toDateString();
        $work = Access::maintenance($r->user())->whereIn('asset_id', $active)->whereNull('archived_at');
        $effective = "(CASE WHEN status='completed' THEN next_due_on ELSE due_on END)";
        $metrics[] = ['id' => 'upcoming-maintenance', 'label' => 'Maintenance in the next 30 days', 'value' => (clone $work)->whereRaw($effective.' BETWEEN ? AND ?', [$today, $through])->count(), 'href' => '/assets?'.http_build_query([...$r->only(['type', 'location', 'owner_id']), 'section' => 'maintenance', 'view' => 'upcoming'])];
        $metrics[] = ['id' => 'overdue-maintenance', 'label' => 'Overdue maintenance dates', 'value' => (clone $work)->whereRaw($effective.' < ?', [$today])->count(), 'href' => '/assets?'.http_build_query([...$r->only(['type', 'location', 'owner_id']), 'section' => 'maintenance', 'view' => 'overdue'])];
        $metrics[] = ['id' => 'overdue-returns', 'label' => 'Overdue returns', 'value' => Access::assignments($r->user())->whereIn('asset_id', $active)->whereNull('returned_on')->where('expected_return_on', '<', $today)->count(), 'href' => '/assets?'.http_build_query([...$r->only(['type', 'location', 'owner_id']), 'section' => 'assignments', 'view' => 'overdue'])];
        $table = app(Query::class)->run($this->query($r, $s), 'assets.'.$s, $r);
        $table['page']->through(fn ($row) => $s === 'register' ? [...$this->serializeAsset($row), 'record_url' => '/assets/register/'.$row->id, 'record_link_column' => 'name'] : ($s === 'assignments' ? [...$this->serializeAssignment($r->user(), $row), 'record_url' => '/assets/register/'.$row->asset_id, 'record_link_column' => 'asset'] : [...$this->serializeMaintenance($r->user(), $row), 'record_url' => '/assets/maintenance/'.$row->id, 'record_link_column' => 'action']));

        return Inertia::render('Assets/Index', [...$this->options($r->user()), 'section' => $s, 'table' => $table, 'metrics' => $metrics, 'filters' => $r->only(['type', 'location', 'owner_id', 'status', 'from', 'to', 'view'])]);
    }

    public function store(Request $r, Records $service)
    {
        $record = $service->save($r->user(), $r->all());

        return redirect(Access::query($r->user())->whereKey($record->id)->exists() ? '/assets/register/'.$record->id : '/assets');
    }

    public function update(Request $r, int $id, Records $service)
    {
        $service->save($r->user(), $r->all(), $id);

        return Access::query($r->user())->whereKey($id)->exists() ? back() : redirect('/assets');
    }

    public function archive(Request $r, int $id, Records $service)
    {
        $service->archive($r->user(), $id, $r->all());

        return redirect('/assets');
    }

    public function show(Request $r, int $id)
    {
        $u = Access::fresh($r->user());
        $asset = Access::query($u)->findOrFail($id);
        $editable = Access::editable($u, $asset);
        $docs = $u->can('assets.documents.view') ? AssetDocument::where('asset_id', $id)->orderByDesc('id')->get()->map(fn ($d) => ['id' => $d->id, 'version' => $d->version, 'name' => $d->name, 'href' => '/assets/documents/'.$d->id, 'archived_at' => $d->archived_at])->all() : [];

        return Inertia::render('Assets/Record', [...$this->options($u, $asset), 'record' => $this->serializeAsset($asset), 'editable' => $editable, 'canCreateAssignment' => $editable && $u->can('assets.assign'), 'canReturnAssignment' => $editable && $u->can('assets.assign'), 'canUpdateAssignment' => $editable && $u->can('assets.assign'), 'canCreateMaintenance' => $editable && $u->can('assets.create') && $asset->status !== 'retired', 'history' => $this->history($u, $asset), 'documents' => $docs, 'assignments' => Access::assignments($u)->where('asset_id', $id)->orderByDesc('id')->get()->map(fn ($a) => $this->serializeAssignment($u, $a))->all(), 'maintenance' => Access::maintenance($u)->where('asset_id', $id)->orderByDesc('id')->get()->map(fn ($m) => $this->serializeMaintenance($u, $m))->all()]);
    }

    public function assign(Request $r, int $id, Assignments $service)
    {
        $service->create($r->user(), $id, $r->all());

        return back();
    }

    public function updateAssignment(Request $r, int $id, Assignments $service)
    {
        $service->update($r->user(), $id, $r->all());

        return back();
    }

    public function returnAssignment(Request $r, int $id, Assignments $service)
    {
        $service->return($r->user(), $id, $r->all());

        return back();
    }

    public function storeMaintenance(Request $r, int $id, Maintenance $service)
    {
        $m = $service->save($r->user(), $id, $r->all());

        return redirect('/assets/maintenance/'.$m->id);
    }

    public function updateMaintenance(Request $r, int $id, Maintenance $service)
    {
        $m = Access::maintenance($r->user())->findOrFail($id);
        $service->save($r->user(), $m->asset_id, $r->all(), $id);

        return back();
    }

    public function showMaintenance(Request $r, int $id)
    {
        $u = Access::fresh($r->user());
        $m = Access::maintenance($u)->findOrFail($id);
        $asset = Access::query($u)->findOrFail($m->asset_id);
        $editable = Access::editable($u, $asset) && $m->archived_at === null;
        $data = $this->serializeMaintenance($u, $m);
        $events = MaintenanceCompletion::where('maintenance_id', $id)->orderByDesc('id')->get()->map(function ($e) use ($u) {
            $data = [...$e->toArray(), 'actor' => $this->person($e->actor_id)];
            if ($u->can('assets.costs.view')) {
                $cost = MaintenanceCost::find($e->getAttribute('cost_id'));
                $data['cost'] = $cost?->amount;
                $data['currency'] = $cost?->currency;
            }

            return $data;
        })->all();

        return Inertia::render('Assets/Maintenance', [...$this->options($u, $asset, $m), 'record' => $data, 'editable' => $editable, 'canComplete' => $editable && $m->status !== 'completed', 'canArchiveMaintenance' => $editable && $u->can('assets.archive'), 'canRecordCost' => $editable && $m->status === 'completed' && $u->can('assets.costs.view') && $u->can('assets.costs.manage'), ...($u->can('assets.costs.view') ? ['costHistory' => MaintenanceCost::where('maintenance_id', $id)->latest('id')->get()->map(fn ($cost) => ['id' => $cost->id, 'amount' => $cost->amount, 'currency' => $cost->currency, 'actor' => $this->person($cost->actor_id), 'created_at' => $cost->getAttribute('created_at')->toISOString()])->all()] : []), 'history' => $this->history($u, $asset, $id), 'completions' => $events]);
    }

    public function completeMaintenance(Request $r, int $id, Maintenance $service)
    {
        $service->complete($r->user(), $id, $r->all());

        return back();
    }

    public function recordCost(Request $r, int $id, Maintenance $service)
    {
        $service->recordCost($r->user(), $id, $r->all());

        return back();
    }

    public function archiveMaintenance(Request $r, int $id, Maintenance $service)
    {
        $service->archive($r->user(), $id, $r->all());

        return back();
    }

    public function addDocument(Request $r, int $id, Records $service)
    {
        $service->document($r->user(), $id, $r->all());

        return back();
    }

    public function archiveDocument(Request $r, int $id, Records $service)
    {
        $service->archiveDocument($r->user(), $id, $r->all());

        return back();
    }

    public function document(Request $r, int $id, DocumentLinks $links)
    {
        $u = Access::fresh($r->user());
        abort_unless($u->can('assets.documents.view'), 403);
        $doc = AssetDocument::findOrFail($id);
        Access::query($u)->findOrFail($doc->asset_id);

        return $links->redirect($doc->getAttribute('url'));
    }

    public function classify(Request $r, IdentityRelationships $service)
    {
        $u = Access::fresh($r->user());
        abort_unless(Access::available($u) && ($u->can('assets.create') || $u->can('assets.assign')), 403);
        $data = $r->validate(['role' => ['required', Rule::in(['customer', 'supplier'])]]);
        $service->create($u, [...$r->only(['contact_id', 'company_id', 'responsible_id', 'main_contact_id', 'active', 'notes']), 'role' => $data['role']]);

        return back();
    }

    public function export(Request $r, Csv $csv)
    {
        $s = $this->filters($r);
        $u = Access::fresh($r->user());
        abort_unless($u->can('assets.export'), 403);
        $q = app(Query::class)->filtered($this->query($r, $s), 'assets.'.$s, $r);
        abort_if((clone $q)->count() > 5000, 422, 'Narrow the filters to export at most 5,000 asset records.');
        $headers = match ($s) {
            'register' => ['id', 'code', 'name', 'type', 'location', 'owner', 'status', 'purchased_on', 'serial_number', 'notes'],'assignments' => ['id', 'asset', 'target_type', 'employee', 'customer', 'location', 'assigned_on', 'expected_return_on', 'returned_on', 'return_condition', 'notes'],'maintenance' => ['id', 'asset', 'action', 'description', 'owner', 'supplier', 'due_on', 'status', 'completed_on', 'next_due_on', ...($u->can('assets.costs.view') ? ['cost', 'currency'] : [])],default => abort(404)
        };
        $rows = (function () use ($q, $u, $headers) {
            foreach ($q->cursor() as $row) {
                $data = match (true) {
                    $row instanceof Asset => $this->serializeAsset($row),
                    $row instanceof Assignment => $this->serializeAssignment($u, $row),
                    $row instanceof MaintenanceRecord => $this->serializeMaintenance($u, $row),
                    default => throw new \LogicException('Unsupported asset export record.'),
                };
                yield array_map(fn ($f) => is_array($data[$f] ?? null) ? ($data[$f]['name'] ?? '') : ($data[$f] ?? ''), $headers);
            }
        })();

        return $csv->download('assets-'.$s.'.csv', $headers, $rows);
    }
}
