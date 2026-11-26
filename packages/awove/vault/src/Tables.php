<?php

namespace Awove\Vault;

use Awove\Core\Tables\Column;

final class Tables
{
    public function columns(string $id, $user): array
    {
        if ($id !== 'vault.items' || ! Access::has($user, 'list')) {
            return [];
        }
        $types = array_map(fn ($id, $label) => ['value' => $id, 'label' => (string) __($label)], array_keys(Schema::TYPES), array_values(Schema::TYPES));

        return [new Column('title', 'Vault item title', 'vault_items.title', width: 260),
            new Column('type', 'Item type', 'vault_items.type', 'select', options: $types),
            new Column('customer', 'Customer', 'COALESCE((SELECT name FROM crm_contacts WHERE id=vault_items.contact_id),(SELECT name FROM crm_companies WHERE id=vault_items.company_id))', width: 260),
            new Column('level', 'Access level', 'vault_items.level', 'select', options: [['value' => '1', 'label' => __('Level 1 — restricted')], ['value' => '2', 'label' => __('Level 2 — customer permissions')]]),
            new Column('updated_at', 'Last updated', 'vault_items.updated_at', 'datetime')];
    }

    public function defaults(string $id): array
    {
        return $id === 'vault.items' ? ['title', 'type', 'customer', 'level', 'updated_at'] : [];
    }
}
