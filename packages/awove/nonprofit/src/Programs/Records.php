<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Installation;
use Awove\Core\Models\Contact;
use Awove\Core\Services\DocumentLinks;
use Awove\Nonprofit\Programs\Models\Attendance;
use Awove\Nonprofit\Programs\Models\Document;
use Awove\Nonprofit\Programs\Models\History;
use Awove\Nonprofit\Programs\Models\Participant;
use Awove\Nonprofit\Programs\Models\Record;
use Awove\Nonprofit\Programs\Models\Session;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Records
{
    public static function today(): string
    {
        return now(Installation::settings()['timezone'])->toDateString();
    }

    public static function statuses(): array
    {
        $defaults = ['present' => 'Present', 'absent' => 'Absent', 'excused' => 'Excused'];
        $labels = Installation::manifest()['nonprofit_attendance_statuses'] ?? [];
        if (! is_array($labels)) {
            throw new \RuntimeException('Attendance labels must be a map.');
        }foreach (array_intersect_key($labels, $defaults) as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Attendance labels need non-empty text up to 100 characters.');
            }
        }

        return array_replace($defaults, array_intersect_key($labels, $defaults));
    }

    public static function clean(array $input): array
    {
        foreach ($input as $k => $v) {
            if (is_string($v)) {
                $input[$k] = trim($v) === '' ? null : trim($v);
            }
        }

        return $input;
    }

    public function version(Record $r, array $input, string $key = 'version'): void
    {
        $d = validator($input, [$key => 'required|integer|min:1'])->validate();
        abort_unless($r->version === (int) $d[$key], 409, 'This record changed. Reload before saving.');
    }

    public function log($u, string $kind, Record $r, string $event, array $fields = []): void
    {
        History::create(['kind' => $kind, 'record_id' => $r->id, 'program_id' => in_array($kind, ['sessions', 'feedback', 'documents']) ? $r->getAttribute('program_id') : ($kind === 'programs' ? $r->id : ($kind === 'attendance' ? Session::findOrFail($r->getAttribute('session_id'))->getAttribute('program_id') : null)), 'participant_id' => $kind === 'participants' ? $r->id : ($kind === 'attendance' ? $r->getAttribute('participant_id') : null), 'actor_id' => $u->id, 'event' => $event, 'changed_fields' => $fields]);
    }

    private function owner($u, string $kind, ?int $id, ?Record $existing = null, ?Record $program = null, string $field = 'owner_id'): void
    {
        if ($existing && $existing->getAttribute($field) === $id) {
            return;
        }
        abort_unless($id === $u->id || $u->can(Access::prefix($kind).'.assign'), 403);
        if ($id === null) {
            return;
        }$person = config('auth.providers.users.model')::where('active', true)->find($id);
        if (! Access::available($person, $kind) || ($program && ! Access::query($person, 'programs')->whereKey($program->id)->exists())) {
            throw ValidationException::withMessages([$field => 'Choose active staff with existing access to this record.']);
        }
    }

    private function relation($u, string $kind, string $field, array &$data, ?Record $existing): void
    {
        $old = $existing?->getAttribute($field);
        $next = $data[$field] ?? null;
        $next = $next === null ? null : (int) $next;
        $query = $kind === 'contacts' ? IdentityAccess::identities($u, Contact::class) : Access::query($u, $kind);
        if ($old !== null && ! (clone $query)->whereKey($old)->exists()) {
            if ($next === null || $next === $old) {
                $data[$field] = $old;

                return;
            }abort(403, 'The existing related record is restricted.');
        }
        if ($next === $old) {
            $data[$field] = $next;

            return;
        }
        if ($next !== null) {
            $r = (clone $query)->find($next);
            if (! $r || ($kind !== 'contacts' && ($r->getAttribute('archived_at') || ! $r->getAttribute('active')))) {
                throw ValidationException::withMessages([$field => 'Choose an active record you can access.']);
            }
        }
        $data[$field] = $next;
    }

    public function save($u, string $kind, array $input, ?int $id = null): Record
    {
        abort_unless(in_array($kind, ['programs', 'participants', 'locations', 'groups']), 404);
        try {
            return DB::transaction(function () use ($u, $kind, $input, $id) {
                $u = Access::fresh($u);
                $input = self::clean($input);
                $r = $id ? Access::query($u, $kind)->lockForUpdate()->findOrFail($id) : null;
                if ($r) {
                    Access::edit($u, $kind, $r);
                    $this->version($r, $input);
                } else {
                    abort_unless(Access::available($u, $kind) && $u->can(Access::prefix($kind).'.create'), 403);
                }
                $values = [...($r?->getAttributes() ?? ['owner_id' => $u->id, 'active' => true]), ...$input];
                $rules = ['name' => 'required|string|max:180', 'owner_id' => 'nullable|integer|min:1', 'active' => 'required|boolean', 'notes' => 'nullable|string|max:10000'];
                if (in_array($kind, ['groups', 'programs', 'participants'])) {
                    $rules['location_id'] = 'nullable|integer|min:1';
                }
                if (in_array($kind, ['programs', 'participants'])) {
                    $rules['group_id'] = 'nullable|integer|min:1';
                }
                if ($kind === 'programs') {
                    $rules = [...$rules, 'starts_on' => 'nullable|date_format:Y-m-d', 'ends_on' => 'nullable|date_format:Y-m-d'.(! empty($values['starts_on']) ? '|after_or_equal:starts_on' : '')];
                }
                if ($kind === 'participants') {
                    $rules = [...$rules, 'participant_code' => 'required|string|max:80', 'email' => 'nullable|email|max:254', 'phone' => 'nullable|string|max:80', 'contact_id' => 'nullable|integer|min:1', 'program_id' => 'nullable|integer|min:1'];
                }
                $data = validator($values, $rules)->validate();
                $data['owner_id'] = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
                $this->owner($u, $kind, $data['owner_id'], $r);
                foreach (['location_id' => 'locations', 'group_id' => 'groups', 'program_id' => 'programs', 'contact_id' => 'contacts'] as $field => $related) {
                    if (array_key_exists($field, $rules)) {
                        $this->relation($u, $related, $field, $data, $r);
                    }
                }
                if ($kind === 'participants' && Participant::whereRaw('lower(participant_code)=lower(?)', [$data['participant_code']])->when($r, fn ($q) => $q->whereKeyNot($r->id))->exists()) {
                    throw ValidationException::withMessages(['participant_code' => 'This participant ID is already in use.']);
                }
                $before = $r?->getAttributes() ?? [];
                $model = Access::MODELS[$kind];
                $r ??= new $model;
                $r->fill($data);
                $r->setAttribute('version', ($r->exists ? $r->version : 0) + 1);
                if (! $r->exists) {
                    $r->setAttribute('created_by', $u->id);
                }$r->save();
                $changed = array_keys(array_diff_assoc($r->getAttributes(), $before));
                $this->log($u, $kind, $r, $id ? 'updated' : 'created', array_values(array_diff($changed, ['version', 'updated_at', 'created_at', 'created_by', 'id'])));

                return $r;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['participant_code' => 'This participant ID is already in use.']);
            }throw $e;
        }
    }

    public function child($u, string $kind, int $programId, array $input, ?int $id = null): Record
    {
        abort_unless(in_array($kind, ['sessions', 'feedback']), 404);

        return DB::transaction(function () use ($u, $kind, $programId, $input, $id) {
            $u = Access::fresh($u);
            $program = Access::query($u, 'programs')->lockForUpdate()->findOrFail($programId);
            Access::edit($u, 'programs', $program);
            $this->version($program, $input, 'program_version');
            $input = self::clean($input);
            $r = $id ? Access::query($u, $kind)->where('program_id', $program->id)->lockForUpdate()->findOrFail($id) : null;
            if ($r) {
                Access::edit($u, $kind, $r);
                $this->version($r, $input);
            } else {
                abort_unless($u->can('nonprofit.programs.create'), 403);
            }
            $rules = $kind === 'sessions' ? ['name' => 'required|string|max:180', 'session_on' => 'required|date_format:Y-m-d', 'location_id' => 'nullable|integer|min:1', 'group_id' => 'nullable|integer|min:1', 'notes' => 'nullable|string|max:10000'] : ['feedback_on' => 'required|date_format:Y-m-d|before_or_equal:'.self::today(), 'comments' => 'required|string|max:10000', 'follow_up_owner_id' => 'nullable|integer|min:1', 'next_action' => 'nullable|string|max:500', 'due_on' => 'nullable|date_format:Y-m-d', 'completed_on' => 'nullable|date_format:Y-m-d|before_or_equal:'.self::today()];
            $defaults = $kind === 'sessions' ? ['location_id' => $program->getAttribute('location_id'), 'group_id' => $program->getAttribute('group_id')] : ['follow_up_owner_id' => $u->id];
            $data = validator([...($r?->getAttributes() ?? $defaults), ...$input], $rules)->validate();
            if ($kind === 'sessions') {
                foreach (['location_id' => 'locations', 'group_id' => 'groups'] as $f => $k) {
                    $this->relation($u, $k, $f, $data, $r);
                }
            } else {
                $data['follow_up_owner_id'] = isset($data['follow_up_owner_id']) ? (int) $data['follow_up_owner_id'] : null;
                $this->owner($u, 'programs', $data['follow_up_owner_id'], $r, $program, 'follow_up_owner_id');
                if (($data['due_on'] ?? null) && (! $data['follow_up_owner_id'] || ! ($data['next_action'] ?? null))) {
                    throw ValidationException::withMessages(['due_on' => 'A dated follow-up needs a next action and responsible staff.']);
                }
                if (($data['completed_on'] ?? null) && ! ($data['next_action'] ?? null)) {
                    throw ValidationException::withMessages(['completed_on' => 'Record the action being completed.']);
                }
            }
            $before = $r?->getAttributes() ?? [];
            $model = Access::MODELS[$kind];
            $r ??= new $model;
            $r->fill([...$data, 'program_id' => $program->id]);
            $r->setAttribute('version', ($r->exists ? $r->version : 0) + 1);
            if (! $r->exists) {
                $r->setAttribute('created_by', $u->id);
            }$r->save();
            $program->increment('version');
            $this->log($u, $kind, $r, $id ? 'updated' : 'created', array_values(array_diff(array_keys(array_diff_assoc($r->getAttributes(), $before)), ['id', 'version', 'updated_at', 'created_at', 'created_by'])));

            return $r;
        });
    }

    public function attend($u, int $sessionId, array $input, ?int $id = null): Record
    {
        try {
            return DB::transaction(function () use ($u, $sessionId, $input, $id) {
                $u = Access::fresh($u);
                $found = Access::query($u, 'sessions')->findOrFail($sessionId);
                $program = Access::query($u, 'programs')->lockForUpdate()->findOrFail($found->getAttribute('program_id'));
                Access::edit($u, 'programs', $program);
                $this->version($program, $input, 'program_version');
                $session = Access::query($u, 'sessions')->lockForUpdate()->findOrFail($sessionId);
                Access::edit($u, 'sessions', $session);
                $this->version($session, $input, 'session_version');
                abort_unless($u->can('nonprofit.programs.attendance.manage') && Access::available($u, 'participants'), 403);
                $r = $id ? Access::query($u, 'attendance')->where('session_id', $sessionId)->lockForUpdate()->findOrFail($id) : null;
                if ($r) {
                    $this->version($r, $input);
                }
                $data = validator(self::clean([...($r?->getAttributes() ?? []), ...$input]), ['participant_id' => 'required|integer|min:1', 'status' => ['required', Rule::in(array_keys(self::statuses()))], 'notes' => 'nullable|string|max:3000'])->validate();
                if ($r && $r->getAttribute('participant_id') !== (int) $data['participant_id']) {
                    throw ValidationException::withMessages(['participant_id' => 'Attendance keeps its original participant.']);
                }
                $participant = Access::query($u, 'participants')->lockForUpdate()->findOrFail($data['participant_id']);
                if (! $r && ($participant->archived_at || ! $participant->getAttribute('active'))) {
                    throw ValidationException::withMessages(['participant_id' => 'Choose an active participant.']);
                }
                if (! $r && Attendance::where('session_id', $sessionId)->where('participant_id', $participant->id)->exists()) {
                    throw ValidationException::withMessages(['participant_id' => 'Attendance is already recorded for this participant and session. Edit the existing record.']);
                }
                $r ??= new Attendance;
                $r->fill([...$data, 'participant_id' => $participant->id, 'session_id' => $sessionId]);
                $r->setAttribute('version', ($r->exists ? $r->version : 0) + 1);
                if (! $r->exists) {
                    $r->setAttribute('created_by', $u->id);
                }$r->save();
                $session->increment('version');
                $program->increment('version');
                $this->log($u, 'attendance', $r, $id ? 'updated' : 'created', ['status', 'notes']);

                return $r;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505') {
                throw ValidationException::withMessages(['participant_id' => 'Attendance is already recorded for this session.']);
            }throw $e;
        }
    }

    public function archive($u, string $kind, int $id, array $input): void
    {
        abort_unless($kind !== 'attendance', 404);
        DB::transaction(function () use ($u, $kind, $id, $input) {
            $u = Access::fresh($u);
            $r = Access::query($u, $kind)->findOrFail($id);
            if (in_array($kind, ['sessions', 'feedback'])) {
                $parent = Access::query($u, 'programs')->lockForUpdate()->findOrFail($r->getAttribute('program_id'));
                Access::edit($u, 'programs', $parent);
                $this->version($parent, $input, 'program_version');
            }
            $r = Access::query($u, $kind)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $kind, $r);
            abort_unless($u->can(Access::prefix($kind).'.archive'), 403);
            $this->version($r, $input);
            $r->forceFill(['archived_at' => now(), 'version' => $r->version + 1])->save();
            if (isset($parent)) {
                $parent->increment('version');
            }$this->log($u, $kind, $r, 'archived', ['archived_at']);
        });
    }

    public function document($u, int $programId, array $input): Document
    {
        return DB::transaction(function () use ($u, $programId, $input) {
            $u = Access::fresh($u);
            $p = Access::query($u, 'programs')->lockForUpdate()->findOrFail($programId);
            Access::edit($u, 'programs', $p);
            $this->version($p, $input, 'program_version');
            abort_unless($u->can('nonprofit.programs.documents.view') && $u->can('nonprofit.programs.documents.manage'), 403);
            $d = validator(self::clean($input), ['name' => 'required|string|max:180', 'url' => 'required|string|max:2048'])->validate();
            $d['url'] = app(DocumentLinks::class)->validate($d['url']);
            $r = Document::create([...$d, 'program_id' => $p->id, 'created_by' => $u->id, 'version' => 1]);
            $p->increment('version');
            $this->log($u, 'documents', $r, 'created', ['name', 'url']);

            return $r;
        });
    }

    public function archiveDocument($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $d = Document::findOrFail($id);
            $p = Access::query($u, 'programs')->lockForUpdate()->findOrFail($d->getAttribute('program_id'));
            Access::edit($u, 'programs', $p);
            $this->version($p, $input, 'program_version');
            abort_unless($u->can('nonprofit.programs.documents.view') && $u->can('nonprofit.programs.documents.manage'), 403);
            $d = Document::lockForUpdate()->findOrFail($id);
            $this->version($d, $input);
            abort_if($d->archived_at !== null, 409);
            $d->forceFill(['version' => $d->version + 1, 'archived_at' => now()])->save();
            $p->increment('version');
            $this->log($u, 'documents', $d, 'archived', ['archived_at']);
        });
    }
}
