<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Csv;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Tables\Query;
use Awove\Nonprofit\NonprofitModule;
use Awove\Nonprofit\Programs\Models\Document;
use Awove\Nonprofit\Programs\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class Controller
{
    public function __construct(private Records $records, private Presentation $present, private Query $tables) {}

    public function filters(Request $request): array
    {
        return validator($request->all(), ['view' => ['nullable', Rule::in(['all', 'active', 'inactive', 'archived', 'due', 'completed'])], 'program_id' => 'nullable|integer|min:1', 'location_id' => 'nullable|integer|min:1', 'group_id' => 'nullable|integer|min:1', 'owner_id' => 'nullable|integer|min:1', 'from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d'.($request->filled('from') ? '|after_or_equal:from' : '')])->validate();
    }

    public function query(Request $request, string $kind)
    {
        $u = Access::fresh($request->user());
        abort_unless(Access::available($u, $kind), 403);
        $q = Access::query($u, $kind);
        $f = $this->filters($request);
        $archived = ($f['view'] ?? 'all') === 'archived';
        if ($kind === 'attendance') {
            $sessions = Access::query($u, 'sessions');
            if ($archived) {
                $sessions->where(fn ($q) => $q->whereNotNull('archived_at')->orWhereIn('program_id', Access::query($u, 'programs')->whereNotNull('archived_at')->select('id')));
            } else {
                $sessions->whereNull('archived_at')->whereIn('program_id', Access::query($u, 'programs')->whereNull('archived_at')->select('id'));
            }
            $q->whereIn('session_id', $sessions->select('id'));
        } elseif (in_array($kind, ['sessions', 'feedback'])) {
            if ($archived) {
                $q->where(fn ($q) => $q->whereNotNull('archived_at')->orWhereIn('program_id', Access::query($u, 'programs')->whereNotNull('archived_at')->select('id')));
            } else {
                $q->whereNull('archived_at')->whereIn('program_id', Access::query($u, 'programs')->whereNull('archived_at')->select('id'));
            }
        } else {
            $archived ? $q->whereNotNull('archived_at') : $q->whereNull('archived_at');
        }
        if (in_array($kind, ['programs', 'participants', 'groups', 'locations']) && in_array($f['view'] ?? '', ['active', 'inactive'])) {
            $q->where('active', $f['view'] === 'active');
        }
        if (isset($f['owner_id'])) {
            if ($kind === 'attendance') {
                $q->whereIn('session_id', Access::query($u, 'sessions')->whereIn('program_id', Access::query($u, 'programs')->where('owner_id', $f['owner_id'])->select('id'))->select('id'));
            } elseif ($kind === 'sessions') {
                $q->whereIn('program_id', Access::query($u, 'programs')->where('owner_id', $f['owner_id'])->select('id'));
            } else {
                $q->where($kind === 'feedback' ? 'follow_up_owner_id' : 'owner_id', $f['owner_id']);
            }
        }
        foreach (['program_id' => 'programs', 'location_id' => 'locations', 'group_id' => 'groups'] as $field => $related) {
            if (! isset($f[$field])) {
                continue;
            }
            abort_unless(Access::query($u, $related)->whereKey($f[$field])->exists(), 403);
            if ($kind === 'attendance') {
                $q->whereIn('session_id', Access::query($u, 'sessions')->where($field, $f[$field])->select('id'));
            } elseif ($kind === 'programs' && $field === 'program_id') {
                $q->whereKey($f[$field]);
            } elseif ($kind === 'feedback' && $field !== 'program_id') {
                $q->whereIn('program_id', Access::query($u, 'programs')->where($field, $f[$field])->select('id'));
            } elseif (in_array($kind, ['programs', 'participants', 'sessions', 'feedback']) || ($kind === 'groups' && $field === 'location_id')) {
                $q->where($field, $f[$field]);
            }
        }
        if ($kind === 'attendance') {
            foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
                if (isset($f[$key])) {
                    $q->whereIn('session_id', Access::query($u, 'sessions')->where('session_on', $op, $f[$key])->select('id'));
                }
            }
        } else {
            $date = match ($kind) {
                'sessions' => 'session_on','feedback' => 'feedback_on','programs' => 'starts_on',default => 'created_at'
            };
            foreach (['from' => '>=', 'to' => '<='] as $key => $op) {
                if (isset($f[$key])) {
                    $q->whereDate($date, $op, $f[$key]);
                }
            }
        }
        if ($kind === 'feedback') {
            if (($f['view'] ?? '') === 'due') {
                $q->whereNull('completed_on')->where('due_on', '<=', Records::today());
            }
            if (($f['view'] ?? '') === 'completed') {
                $q->whereNotNull('completed_on');
            }
        }

        return $q;
    }

    private function options($u, ?Record $program = null): array
    {
        $u = Access::fresh($u);
        $options = [];
        foreach (['programs', 'locations', 'groups', 'participants'] as $kind) {
            $options[$kind] = Access::query($u, $kind)->whereNull('archived_at')->where('active', true)->orderBy('name')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->getAttribute('name'), 'participant_code' => $kind === 'participants' ? $r->getAttribute('participant_code') : null])->all();
        }
        $options['contacts'] = Access::available($u, 'participants') ? IdentityAccess::identities($u, Contact::class)->orderBy('name')->get()->map(fn ($r) => ['id' => $r->getKey(), 'name' => $r->getAttribute('name')])->all() : [];

        return [...$options, 'sections' => app(NonprofitModule::class)->sections($u), 'owners' => Access::owners($u), 'participantOwners' => Access::owners($u, 'participants'), 'followUpOwners' => Access::owners($u, 'programs', $program), 'currentOwnerId' => $u->id, 'today' => Records::today(), 'statuses' => Records::statuses(), 'canViewPrograms' => Access::available($u), 'canViewParticipants' => Access::available($u, 'participants'), 'canAssign' => $u->can('nonprofit.programs.assign'), 'canAssignParticipants' => $u->can('nonprofit.programs.participants.assign'), 'canCreate' => $u->can('nonprofit.programs.create'), 'canCreateParticipants' => $u->can('nonprofit.programs.participants.create'), 'canManageAttendance' => $u->can('nonprofit.programs.attendance.manage') && Access::available($u, 'participants') && Access::available($u), 'canViewDocuments' => $u->can('nonprofit.programs.documents.view'), 'canManageDocuments' => $u->can('nonprofit.programs.documents.view') && $u->can('nonprofit.programs.documents.manage')];
    }

    public function index(Request $request)
    {
        $u = Access::fresh($request->user());
        $kind = $request->query('section', Access::available($u) ? 'programs' : 'participants');
        abort_unless(is_string($kind) && isset(Access::MODELS[$kind]), 404);
        $q = $this->query($request, $kind);
        $table = $this->tables->run($q, 'nonprofit.programs-'.$kind, $request);
        $table['page']->getCollection()->transform(fn ($r) => $this->present->describe($u, $kind, $r));

        return Inertia::render('Nonprofit/Programs/Index', [...$this->options($u), 'section' => $kind, 'table' => $table, 'filters' => $this->filters($request), 'canExport' => $u->can(Access::prefix($kind).'.export'), 'canArchive' => $u->can(Access::prefix($kind).'.archive'), 'summaries' => $kind === 'attendance' ? $this->summaries($request) : null]);
    }

    private function summaries(Request $request): ?array
    {
        if (! Access::available($request->user()) || ! Access::available($request->user(), 'participants')) {
            return null;
        }$q = $this->tables->filtered($this->query($request, 'attendance'), 'nonprofit.programs-attendance', $request);
        $rows = $q->get();
        $groups = [];
        foreach ($rows as $r) {
            $session = Access::query($request->user(), 'sessions')->findOrFail($r->getAttribute('session_id'));
            $program = $this->present->related($request->user(), 'programs', $session->getAttribute('program_id'));
            $location = $this->present->related($request->user(), 'locations', $session->getAttribute('location_id'));
            $key = ($program['id'] ?? 0).':'.($location['id'] ?? 0);
            $groups[$key] ??= ['program' => $program['name'] ?? 'Restricted program', 'location' => $location['name'] ?? 'Not specified', 'present' => 0, 'absent' => 0, 'excused' => 0];
            $groups[$key][$r->getAttribute('status')]++;
        }

        return array_values($groups);
    }

    public function show(Request $request, string $kind, int $id)
    {
        $u = Access::fresh($request->user());
        abort_unless(isset(Access::MODELS[$kind]), 404);
        $r = Access::query($u, $kind)->findOrFail($id);
        $program = in_array($kind, ['sessions', 'feedback']) ? Access::program($u, $r) : ($kind === 'programs' ? $r : null);
        $data = ['kind' => $kind, 'record' => $this->present->describe($u, $kind, $r), 'history' => $this->present->history($u, $kind, $r), 'canArchive' => $u->can(Access::prefix($kind).'.archive') && $kind !== 'attendance', 'program' => $program ? $this->present->describe($u, 'programs', $program) : null, 'attendance' => [], 'sessions' => [], 'feedback' => [], 'documents' => []];
        if ($kind === 'programs') {
            foreach (['sessions', 'feedback'] as $child) {
                $data[$child] = Access::query($u, $child)->where('program_id', $r->id)->orderByDesc('id')->get()->map(fn ($row) => $this->present->describe($u, $child, $row))->all();
            }if ($u->can('nonprofit.programs.documents.view')) {
                $data['documents'] = Document::where('program_id', $r->id)->get()->map(fn ($d) => ['id' => $d->id, 'version' => $d->version, 'name' => $d->getAttribute('name'), 'archived_at' => $d->archived_at?->toIso8601String(), 'href' => '/nonprofit/programs/documents/'.$d->id])->all();
            }
        }
        if ($kind === 'sessions') {
            $data['attendance'] = Access::query($u, 'attendance')->where('session_id', $r->id)->get()->map(fn ($row) => $this->present->describe($u, 'attendance', $row))->all();
        }
        if ($kind === 'attendance') {
            $session = Access::query($u, 'sessions')->findOrFail($r->getAttribute('session_id'));
            $data['session'] = $this->present->describe($u, 'sessions', $session);
            $data['program'] = $this->present->describe($u, 'programs', Access::program($u, $session));
        }

        return Inertia::render('Nonprofit/Programs/Record', [...$this->options($u, $program), ...$data]);
    }

    public function store(Request $request, string $kind)
    {
        $r = $this->records->save($request->user(), $kind, $request->all());

        return redirect('/nonprofit/programs/'.$kind.'/'.$r->id);
    }

    public function update(Request $request, string $kind, int $id)
    {
        if (in_array($kind, ['sessions', 'feedback'])) {
            $r = Access::query($request->user(), $kind)->findOrFail($id);
            $this->records->child($request->user(), $kind, $r->getAttribute('program_id'), $request->all(), $id);
        } elseif ($kind === 'attendance') {
            $r = Access::query($request->user(), $kind)->findOrFail($id);
            $this->records->attend($request->user(), $r->getAttribute('session_id'), $request->all(), $id);
        } else {
            $this->records->save($request->user(), $kind, $request->all(), $id);
        }

        return back();
    }

    public function archive(Request $request, string $kind, int $id)
    {
        $this->records->archive($request->user(), $kind, $id, $request->all());

        return back();
    }

    public function child(Request $request, int $program, string $kind)
    {
        $r = $this->records->child($request->user(), $kind, $program, $request->all());

        return redirect('/nonprofit/programs/'.$kind.'/'.$r->id);
    }

    public function attend(Request $request, int $session)
    {
        $this->records->attend($request->user(), $session, $request->all());

        return back();
    }

    public function addDocument(Request $request, int $program)
    {
        $this->records->document($request->user(), $program, $request->all());

        return back();
    }

    public function archiveDocument(Request $request, int $id)
    {
        $this->records->archiveDocument($request->user(), $id, $request->all());

        return back();
    }

    public function document(Request $request, int $id)
    {
        $u = Access::fresh($request->user());
        abort_unless($u->can('nonprofit.programs.documents.view'), 403);
        $d = Document::findOrFail($id);
        $p = Access::query($u, 'programs')->findOrFail($d->getAttribute('program_id'));
        abort_if($d->archived_at || $p->archived_at, 404);

        return app(DocumentLinks::class)->redirect($d->getAttribute('url'));
    }

    public function export(Request $request)
    {
        $u = Access::fresh($request->user());
        $kind = $request->query('section', 'programs');
        abort_unless(is_string($kind) && isset(Access::MODELS[$kind]), 404);
        abort_unless($u->can(Access::prefix($kind).'.export'), 403);
        $q = $this->tables->filtered($this->query($request, $kind), 'nonprofit.programs-'.$kind, $request);
        abort_if((clone $q)->count() > 5000, 422, __('Narrow the export to at most 5,000 records.'));
        $columns = (new Tables)->columns('nonprofit.programs-'.$kind, $u);
        $rows = $q->cursor()->map(function ($r) use ($columns) {
            $row = [];
            foreach (array_keys($columns) as $i) {
                $row[] = $r->getAttribute('dt_'.$i);
            }

            return $row;
        });

        return app(Csv::class)->download('programs-'.$kind.'.csv', array_map(fn ($c) => $c->label, $columns), $rows);
    }
}
