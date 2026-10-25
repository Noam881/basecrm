<?php

namespace Awove\Knowledge;

use Awove\Core\Installation;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Tables\Query;
use Awove\Knowledge\Models\KnowledgeDocument;
use Awove\Knowledge\Models\KnowledgeHistory;
use Awove\Knowledge\Models\RenewalEvent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class KnowledgeController
{
    private function sections($u): array
    {
        return array_values(array_filter(array_keys(Access::MODELS), fn ($s) => Access::available($u, $s)));
    }

    private function filters(Request $r): string
    {
        $sections = $this->sections($r->user());
        abort_unless($sections !== [], 403);
        $s = $r->input('section', $sections[0]);
        $views = match ($s) {
            'library' => ['current', 'missing-owner', 'missing-document', 'archived'],'renewals' => ['open', 'upcoming', 'overdue', 'missing-owner', 'missing-document', 'archived'],'systems' => ['missing-owner', 'archived'],default => []
        };
        if (! Access::fresh($r->user())->can('knowledge.documents.view')) {
            $views = array_values(array_diff($views, ['missing-document']));
        }$r->validate(['section' => ['nullable', Rule::in($sections)], 'owner_id' => 'nullable|integer|min:1', 'kind' => ['nullable', Rule::in(array_keys(Records::KINDS))], 'role' => 'nullable|string|max:160', 'category' => 'nullable|string|max:120', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($r->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in($views)]]);

        return $s;
    }

    private function query(Request $r, string $s)
    {
        $q = Access::query($r->user(), $s);
        $r->input('view') === 'archived' ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        if ($r->filled('owner_id')) {
            $q->where('owner_id', $r->integer('owner_id'));
        }if ($s === 'library') {
            if ($r->filled('kind')) {
                $q->where('kind', $r->input('kind'));
            }foreach (['role' => 'relevant_role', 'category' => 'category'] as $input => $column) {
                if ($r->filled($input)) {
                    $q->where($column, 'ilike', '%'.addcslashes($r->input($input), '%_\\').'%');
                }
            }
        } $date = $s === 'renewals' ? 'expires_on' : ($s === 'library' ? 'updated_on' : null);
        if ($date) {
            if ($r->filled('from')) {
                $q->where($date, '>=', $r->input('from'));
            }if ($r->filled('to')) {
                $q->where($date, '<=', $r->input('to'));
            }
        }
        $view = $r->input('view');
        if ($view === 'missing-owner') {
            $q->whereNull('owner_id');
        }if ($view === 'missing-document' && in_array($s, ['library', 'renewals'], true)) {
            $this->missingDocument($q, $s);
        }if ($s === 'library' && $view === 'current') {
            $q->where('status', 'current');
        }if ($s === 'renewals' && in_array($view, ['open', 'upcoming', 'overdue'], true)) {
            $q->where('status', '!=', 'renewed');
            $today = now(Installation::settings()['timezone'])->toDateString();
            if ($view === 'upcoming') {
                $q->whereBetween('expires_on', [$today, now(Installation::settings()['timezone'])->addDays(30)->toDateString()]);
            }if ($view === 'overdue') {
                $q->where('expires_on', '<', $today);
            }
        }

        return $q;
    }

    private function missingDocument($q, string $s): void
    {
        $table = (new (Access::MODELS[$s]))->getTable();
        $field = $s === 'library' ? 'entry_id' : 'renewal_id';
        $q->where(function ($q) use ($table, $field, $s) {
            $q->whereNotExists(fn ($q) => $q->selectRaw('1')->from('knowledge_documents')->whereColumn('knowledge_documents.'.$field, $table.'.id')->whereNull('knowledge_documents.archived_at'));
            if ($s === 'library') {
                $q->orWhere(function ($q) {
                    $q->where('kind', 'procedure')->whereNull('revision')->whereNull('revised_on');
                })->orWhere(function ($q) {
                    $q->whereIn('kind', ['material', 'training'])->whereNull('updated_on');
                });
            }
        });
    }

    private function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id, ['id', 'name'])?->only(['id', 'name']) : null;
    }

    private function options($u, string $s, ?Model $record = null): array
    {
        $u = Access::fresh($u);
        $owners = Access::owners($u, $s);
        $owner = $record ? $this->person($record->getAttribute('owner_id')) : null;
        if ($owner && ! in_array($owner['id'], array_column($owners, 'id'), true)) {
            $owners[] = $owner;
        }$ids = Access::query($u, $s)->distinct()->pluck('owner_id');

        return ['sections' => $this->sections($u), 'owners' => $owners, 'currentOwnerId' => $u->id, 'filterOwners' => config('auth.providers.users.model')::whereIn('id', $ids)->orderBy('name')->get(['id', 'name']), 'kinds' => Records::KINDS, 'statuses' => Records::statuses(), 'renewalStatuses' => Records::renewalStatuses(), 'today' => now(Installation::settings()['timezone'])->toDateString(), 'canCreate' => $u->can(Access::prefix($s).'.create'), 'canAssign' => $u->can(Access::prefix($s).'.assign'), 'canArchive' => $u->can(Access::prefix($s).'.archive'), 'canExport' => $u->can(Access::prefix($s).'.export'), 'canViewDocuments' => $u->can('knowledge.documents.view'), 'canManageDocuments' => $u->can('knowledge.documents.view') && $u->can('knowledge.documents.manage'), 'canViewSystemReferences' => $u->can('knowledge.systems.references.view'), 'canEditSystemReferences' => $u->can('knowledge.systems.references.view') && $u->can('knowledge.systems.references.edit')];
    }

    private function serialize($u, Model $r): array
    {
        $u = Access::fresh($u);
        $data = $r->toArray();
        $data['owner'] = $this->person($r->getAttribute('owner_id'));
        if (Access::section($r) === 'systems' && $u->can('knowledge.systems.references.view')) {
            $data['password_manager_reference'] = $r->getAttribute('password_manager_reference');
            $data['login_href'] = $r->getAttribute('login_url') ? '/knowledge/systems/'.$r->getKey().'/login' : null;
            if ($u->can('knowledge.systems.references.edit')) {
                $data['login_url'] = $r->getAttribute('login_url');
            }
        }

        return $data;
    }

    private function history($u, Model $r): array
    {
        $u = Access::fresh($u);

        return KnowledgeHistory::where(app(Records::class)->parentField($r), $r->getKey())->orderByDesc('id')->get()->map(fn ($e) => ['id' => $e->id, 'event' => $e->event, 'changed_fields' => array_values(array_filter($e->changed_fields, fn ($f) => ! in_array($f, ['login_url', 'password_manager_reference'], true) || $u->can('knowledge.systems.references.view'))), 'actor' => $this->person($e->actor_id), 'created_at' => $e->getAttribute('created_at')->toISOString()])->all();
    }

    public function index(Request $r)
    {
        $s = $this->filters($r);
        $metrics = [];
        if (Access::available($r->user(), 'library')) {
            $q = $this->query($r, 'library');
            $metrics['current'] = (clone $q)->where('status', 'current')->count();
            $metrics['missing-owner-library'] = (clone $q)->whereNull('owner_id')->count();
            if (Access::fresh($r->user())->can('knowledge.documents.view')) {
                $missing = clone $q;
                $this->missingDocument($missing, 'library');
                $metrics['missing-document'] = $missing->count();
            }
        }if (Access::available($r->user(), 'renewals')) {
            $q = $this->query($r, 'renewals')->where('status', '!=', 'renewed');
            $today = now(Installation::settings()['timezone'])->toDateString();
            $metrics['upcoming'] = (clone $q)->whereBetween('expires_on', [$today, now(Installation::settings()['timezone'])->addDays(30)->toDateString()])->count();
            $metrics['overdue'] = (clone $q)->where('expires_on', '<', $today)->count();
        }$table = app(Query::class)->run($this->query($r, $s), 'knowledge.'.$s, $r);
        $table['page']->through(fn ($record) => [...$this->serialize($r->user(), $record), 'record_url' => '/knowledge/'.$s.'/'.$record->getKey(), 'record_link_column' => $s === 'library' ? 'title' : 'name']);

        return Inertia::render('Knowledge/Index', [...$this->options($r->user(), $s), 'section' => $s, 'metrics' => $metrics, 'table' => $table, 'filters' => $r->only(['owner_id', 'kind', 'role', 'category', 'from', 'to', 'view'])]);
    }

    public function store(Request $r, string $section, Records $service)
    {
        $record = $service->save($r->user(), $section, $r->all());

        return redirect('/knowledge/'.$section.'/'.$record->getKey());
    }

    public function update(Request $r, string $section, int $id, Records $service)
    {
        $service->save($r->user(), $section, $r->all(), $id);

        return back();
    }

    public function archive(Request $r, string $section, int $id, Records $service)
    {
        $service->archive($r->user(), $section, $id, $r->all());

        return redirect('/knowledge?section='.$section);
    }

    public function show(Request $r, string $section, int $id)
    {
        $u = Access::fresh($r->user());
        $record = Access::query($u, $section)->findOrFail($id);
        $docs = [];
        if ($section !== 'systems' && $u->can('knowledge.documents.view')) {
            $docs = KnowledgeDocument::where(app(Records::class)->parentField($record), $id)->orderByDesc('id')->get()->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'version' => $d->version, 'archived_at' => $d->archived_at, 'href' => '/knowledge/documents/'.$d->id])->all();
        }

        return Inertia::render('Knowledge/Record', [...$this->options($u, $section, $record), 'section' => $section, 'record' => $this->serialize($u, $record), 'editable' => Access::editable($u, $record), 'history' => $this->history($u, $record), 'documents' => $docs, 'renewals' => $section === 'renewals' ? RenewalEvent::where('renewal_id', $id)->orderByDesc('id')->get()->map(fn ($e) => [...$e->toArray(), 'actor' => $this->person($e->actor_id)])->all() : [], 'instructionOptions' => $section === 'library' && Access::editable($u, $record) ? app(Instructions::class)->options($u) : [], 'instructions' => $section === 'library' ? app(Instructions::class)->forEntry($u, $id) : []]);
    }

    public function addDocument(Request $r, string $section, int $id, Records $service)
    {
        $service->document($r->user(), $section, $id, $r->all());

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
        abort_unless($u->can('knowledge.documents.view'), 403);
        $doc = KnowledgeDocument::findOrFail($id);
        Access::query($u, $doc->entry_id ? 'library' : 'renewals')->findOrFail($doc->entry_id ?? $doc->renewal_id);

        return $links->redirect($doc->getAttribute('url'));
    }

    public function login(Request $r, int $id, DocumentLinks $links)
    {
        $u = Access::fresh($r->user());
        abort_unless($u->can('knowledge.systems.references.view'), 403);
        $record = Access::query($u, 'systems')->findOrFail($id);
        abort_unless($record->getAttribute('login_url'), 404);

        return $links->redirect($record->getAttribute('login_url'));
    }

    public function renew(Request $r, int $id, Records $service)
    {
        $service->renew($r->user(), $id, $r->all());

        return back();
    }

    public function instruction(Request $r, int $id, Instructions $service)
    {
        $service->add($r->user(), $id, $r->all());

        return back();
    }

    public function archiveInstruction(Request $r, int $id, Instructions $service)
    {
        $service->archive($r->user(), $id, $r->all());

        return back();
    }

    public function export(Request $r, Csv $csv)
    {
        $s = $this->filters($r);
        abort_unless(Access::fresh($r->user())->can(Access::prefix($s).'.export'), 403);
        $q = app(Query::class)->filtered($this->query($r, $s), 'knowledge.'.$s, $r);
        abort_if((clone $q)->count() > 5000, 422, 'Narrow the filters to export at most 5,000 Knowledge records.');
        $headers = match ($s) {
            'library' => ['id', 'kind', 'title', 'category', 'relevant_role', 'purpose', 'use_classification', 'revision', 'revised_on', 'updated_on', 'status', 'owner_id'],'renewals' => ['id', 'name', 'provider', 'expires_on', 'remind_on', 'status', 'owner_id'],'systems' => ['id', 'name', 'owner_id'], default => abort(404)
        };
        $rows = (function () use ($q, $headers) {
            foreach ($q->cursor() as $record) {
                yield array_map(fn ($f) => $record->toArray()[$f] ?? '', $headers);
            }
        })();

        return $csv->download('knowledge-'.$s.'.csv', $headers, $rows);
    }
}
