<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Models\Contact;
use Awove\Nonprofit\Programs\Models\History;
use Awove\Nonprofit\Programs\Models\Record;

class Presentation
{
    public function related($u, string $kind, ?int $id): ?array
    {
        if (! $id) {
            return null;
        }$r = Access::query($u, $kind)->find($id);

        return $r ? ['id' => $r->id, 'name' => $r->getAttribute('name'), 'active' => $r->getAttribute('active'), 'archived' => (bool) $r->archived_at] : null;
    }

    public function describe($u, string $kind, Record $r): array
    {
        $u = Access::fresh($u);
        $hadCells = is_array($r->getAttribute('cells'));
        $query = Access::query($u, $kind)->whereKey($r->id);
        $columns = $hadCells ? (new Tables)->columns('nonprofit.programs-'.$kind, $u) : [];
        if ($columns) {
            $query->select($query->getModel()->getTable().'.*');
            foreach ($columns as $i => $column) {
                $query->selectRaw('('.$column->expression.') AS dt_'.$i);
            }
        }
        $r = $query->firstOrFail();
        $cells = [];
        foreach ($columns as $i => $column) {
            $cells[$column->id] = $r->getAttribute('dt_'.$i);
        }
        $allowed = match ($kind) {
            'programs' => ['name', 'owner_id', 'active', 'notes', 'starts_on', 'ends_on'],'participants' => ['participant_code', 'name', 'email', 'phone', 'owner_id', 'active', 'notes'],'locations','groups' => ['name', 'owner_id', 'active', 'notes'],'sessions' => ['name', 'session_on', 'notes'],'attendance' => ['status', 'notes'],'feedback' => ['feedback_on', 'comments', 'next_action', 'follow_up_owner_id', 'due_on', 'completed_on'],default => []
        };
        $d = array_intersect_key($r->toArray(), array_flip(['id', 'version', 'archived_at', 'created_at', ...$allowed]));
        $d['editable'] = Access::editable($u, $kind, $r);
        if (array_key_exists('owner_id', $d)) {
            $d['owner'] = $this->person($d['owner_id']);
        }
        foreach (['program_id' => 'programs', 'location_id' => 'locations', 'group_id' => 'groups', 'session_id' => 'sessions', 'participant_id' => 'participants'] as $f => $k) {
            if (array_key_exists($f, $r->getAttributes())) {
                $id = $r->getAttribute($f);
                $v = $this->related($u, $k, $id);
                $d[$f] = $v['id'] ?? null;
                $key = substr($f, 0, -3);
                $d[$key] = $v;
                $d[$key.'_restricted'] = $id !== null && $v === null;
            }
        }
        if ($kind === 'participants') {
            $id = $r->getAttribute('contact_id');
            $contact = $id ? IdentityAccess::identities($u, Contact::class)->find($id) : null;
            $d['contact_id'] = $contact?->getKey();
            $d['contact'] = $contact ? ['id' => $contact->getKey(), 'name' => $contact->getAttribute('name'), 'email' => $contact->getAttribute('email'), 'phone' => $contact->getAttribute('phone')] : null;
            $d['contact_restricted'] = $id !== null && ! $contact;
        }
        if ($kind === 'feedback') {
            $d['follow_up_owner'] = $this->person($r->getAttribute('follow_up_owner_id'));
        }
        $d['cells'] = $cells;
        $d['record_url'] = '/nonprofit/programs/'.$kind.'/'.$r->id;
        $d['record_link_column'] = match ($kind) {
            'attendance' => 'participant','feedback' => 'feedback_on',default => 'name'
        };

        return $d;
    }

    public function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id)?->only(['id', 'name']) : null;
    }

    public function history($u, string $kind, Record $r): array
    {
        $u = Access::fresh($u);
        $r = Access::query($u, $kind)->findOrFail($r->id);
        $q = History::query();
        if ($kind === 'programs') {
            $q->where('program_id', $r->id);
        } elseif ($kind === 'participants') {
            $q->where('participant_id', $r->id);
        } else {
            $q->where('kind', $kind)->where('record_id', $r->id);
        }
        $q->where(fn ($q) => $q->whereNull('participant_id')->orWhereIn('participant_id', Access::query($u, 'participants')->select('id')))->where(fn ($q) => $q->whereNull('program_id')->orWhereIn('program_id', Access::query($u, 'programs')->select('id')));
        if (! $u->can('nonprofit.programs.documents.view')) {
            $q->where('kind', '!=', 'documents');
        }

        return $q->orderByDesc('id')->limit(200)->get()->map(fn ($h) => ['id' => $h->id, 'event' => $h->getAttribute('kind').'.'.$h->getAttribute('event'), 'changed_fields' => $h->changed_fields, 'actor' => $this->person($h->getAttribute('actor_id')), 'created_at' => $h->getAttribute('created_at')?->toIso8601String()])->all();
    }
}
