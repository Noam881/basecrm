<?php

namespace Awove\Knowledge;

use Awove\Core\Tables\Column;

class Tables
{
    public function columns(string $id, $u): array
    {
        $u = $u?->fresh();
        $s = substr($id, 10);
        if (! isset(Access::MODELS[$s]) || ! Access::available($u, $s)) {
            return [];
        }$table = (new (Access::MODELS[$s]))->getTable();
        $choices = fn ($options) => array_map(fn ($value, $label) => compact('value', 'label'), array_keys($options), array_values($options));
        $columns = [new Column('id', 'Record ID', "$table.id", 'number'), new Column('owner', 'Owner', "(SELECT name FROM users WHERE users.id=$table.owner_id)")];
        if ($s === 'library') {
            $columns = [...$columns, new Column('title', 'Title or topic', "$table.title", width: 280), new Column('kind', 'Kind', "$table.kind", 'select', options: $choices(Records::KINDS)), new Column('category', 'Category', "$table.category"), new Column('relevant_role', 'Relevant role', "$table.relevant_role"), new Column('purpose', 'Purpose', "$table.purpose", width: 280), new Column('use_classification', 'Use classification', "$table.use_classification"), new Column('revision', 'Current version', "$table.revision"), new Column('revised_on', 'Revision date', "$table.revised_on", 'date'), new Column('updated_on', 'Updated document date', "$table.updated_on", 'date'), new Column('status', 'Status', "$table.status", 'select', options: $choices(Records::statuses()))];
        } elseif ($s === 'renewals') {
            $columns = [...$columns, new Column('name', 'License or insurance', "$table.name", width: 280), new Column('provider', 'Provider', "$table.provider"), new Column('expires_on', 'Expiry date', "$table.expires_on", 'date'), new Column('remind_on', 'Reminder date', "$table.remind_on", 'date'), new Column('status', 'Renewal status', "$table.status", 'select', options: $choices(Records::renewalStatuses())), new Column('notes', 'Notes', "$table.notes", width: 300)];
        } else {
            $columns = [...$columns, new Column('name', 'System name', "$table.name", width: 280)];
        }
        if (in_array($s, ['library', 'renewals'], true) && $u->can('knowledge.documents.view')) {
            $field = $s === 'library' ? 'entry_id' : 'renewal_id';
            $columns[] = new Column('documents_count', 'Current documents', "(SELECT COUNT(*) FROM knowledge_documents WHERE knowledge_documents.$field=$table.id AND knowledge_documents.archived_at IS NULL)", 'number');
        }

        return [...$columns, new Column('created_at', 'Created', "$table.created_at", 'datetime'), new Column('archived_at', 'Archived', "$table.archived_at", 'datetime')];
    }

    public function defaults(string $id): array
    {
        return match ($id) {
            'knowledge.library' => ['title', 'kind', 'owner', 'relevant_role', 'status', 'revision', 'updated_on'],'knowledge.renewals' => ['name', 'provider', 'owner', 'expires_on', 'remind_on', 'status'],'knowledge.systems' => ['name', 'owner'],default => []
        };
    }
}
