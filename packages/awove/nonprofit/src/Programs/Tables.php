<?php

namespace Awove\Nonprofit\Programs;

use Awove\Core\Tables\Column;

class Tables
{
    public static function related($u, string $kind, string $field, string $label = 'name'): string
    {
        $ids = Access::query($u, $kind)->pluck('id')->all();
        if (! $ids) {
            return 'NULL';
        }$model = Access::MODELS[$kind];
        $table = (new $model)->getTable();

        return "(SELECT $label FROM $table WHERE $table.id=$field AND id IN (".implode(',', array_map('intval', $ids)).'))';
    }

    public function columns(string $id, $u): array
    {
        $kind = substr($id, 19);
        if (! isset(Access::MODELS[$kind]) || ! Access::available($u, $kind)) {
            return [];
        }$model = Access::MODELS[$kind];
        $t = (new $model)->getTable();
        $c = [new Column('id', 'Record ID', "$t.id", 'number')];
        if (in_array($kind, ['programs', 'participants', 'locations', 'groups'])) {
            $c[] = new Column('name', 'Name', "$t.name", width: 250);
            $c[] = new Column('owner', 'Responsible person', "(SELECT name FROM users WHERE users.id=$t.owner_id)");
            $c[] = new Column('active', 'Active', "$t.active", 'boolean');
            $c[] = new Column('notes', 'Notes', "$t.notes", width: 280);
        }
        if ($kind === 'participants') {
            $c[] = new Column('participant_code', 'Participant ID', "$t.participant_code");
            $c[] = new Column('email', 'Private email', "$t.email");
            $c[] = new Column('phone', 'Private phone', "$t.phone");
            $c[] = new Column('program', 'Current program', self::related($u, 'programs', "$t.program_id"));
        }
        if (in_array($kind, ['programs', 'groups', 'participants', 'sessions'])) {
            $c[] = new Column('location', 'Location', self::related($u, 'locations', "$t.location_id"));
        }
        if (in_array($kind, ['programs', 'participants', 'sessions'])) {
            $c[] = new Column('group', 'Group', self::related($u, 'groups', "$t.group_id"));
        }
        if ($kind === 'programs') {
            $c[] = new Column('starts_on', 'Start date', "$t.starts_on", 'date');
            $c[] = new Column('ends_on', 'End date', "$t.ends_on", 'date');
        }
        if (in_array($kind, ['sessions', 'feedback'])) {
            $c[] = new Column('program', 'Program', self::related($u, 'programs', "$t.program_id"));
        }
        if ($kind === 'sessions') {
            $c[] = new Column('name', 'Session', "$t.name", width: 250);
            $c[] = new Column('session_on', 'Session date', "$t.session_on", 'date');
            $c[] = new Column('notes', 'Notes', "$t.notes", width: 280);
        }
        if ($kind === 'attendance') {
            $c[] = new Column('participant', 'Participant', self::related($u, 'participants', "$t.participant_id"), width: 220);
            $c[] = new Column('participant_code', 'Participant ID', self::related($u, 'participants', "$t.participant_id", 'participant_code'));
            $c[] = new Column('session', 'Session', self::related($u, 'sessions', "$t.session_id"), width: 240);
            $c[] = new Column('session_on', 'Session date', self::related($u, 'sessions', "$t.session_id", 'session_on'), 'date');
            $c[] = new Column('status', 'Attendance', "$t.status", 'select', options: array_map(fn ($value, $label) => compact('value', 'label'), array_keys(Records::statuses()), array_values(Records::statuses())));
            $c[] = new Column('notes', 'Notes', "$t.notes", width: 280);
        }
        if ($kind === 'feedback') {
            $c[] = new Column('feedback_on', 'Feedback date', "$t.feedback_on", 'date');
            $c[] = new Column('comments', 'Comments', "$t.comments", width: 320);
            $c[] = new Column('follow_up_owner', 'Follow-up owner', "(SELECT name FROM users WHERE users.id=$t.follow_up_owner_id)");
            $c[] = new Column('next_action', 'Next action', "$t.next_action", width: 260);
            $c[] = new Column('due_on', 'Follow-up date', "$t.due_on", 'date');
            $c[] = new Column('completed_on', 'Completed date', "$t.completed_on", 'date');
        }

        return [...$c, new Column('created_at', 'Created', "$t.created_at", 'datetime')];
    }

    public function defaults(string $id): array
    {
        return match (substr($id, strlen('nonprofit.programs-'))) {
            'programs' => ['name', 'owner', 'location', 'group', 'starts_on', 'ends_on', 'active'],'participants' => ['participant_code', 'name', 'program', 'location', 'active'],'sessions' => ['name', 'program', 'session_on', 'location', 'group'],'attendance' => ['participant', 'session', 'session_on', 'status'],'feedback' => ['feedback_on', 'program', 'next_action', 'follow_up_owner', 'due_on', 'completed_on'],default => ['name', 'owner', 'location', 'active']
        };
    }
}
