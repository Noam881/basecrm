<?php

namespace Awove\Vault;

use Awove\Core\Contracts\IdentityPanel;
use Awove\Core\Contracts\IdentityPanelContributor;
use Awove\Core\Contracts\Module;
use Awove\Core\Contracts\NavigationItem;
use Awove\Core\Contracts\RecordTableContributor;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;

class VaultModule implements IdentityPanelContributor, Module, RecordTableContributor
{
    public function id(): string
    {
        return 'vault';
    }

    public function version(): string
    {
        return '1.0.0';
    }

    public function contract(): int
    {
        return 1;
    }

    public function dependencies(): array
    {
        return [];
    }

    public function permissions(): array
    {
        return array_map(fn ($name) => 'vault.'.$name, ['list', 'read', 'create', 'update', 'delete', 'level1', 'change-level', 'transfer', 'files.upload', 'files.download', 'files.delete', 'admin']);
    }

    public function navigation(): array
    {
        return [new NavigationItem((string) __('Vault'), '/vault', 'vault.list')];
    }

    public function panels(): array
    {
        return [];
    }

    public function identityPanels($user, Contact|Company $identity): array
    {
        $type = $identity instanceof Contact ? 'contacts' : 'companies';

        return [new IdentityPanel('vault.customer', 'Vault', 'Vault/Panel', '/vault/api/items?customer_type='.$type.'&customer_id='.$identity->id, 'vault.list')];
    }

    public function tableIds(): array
    {
        return ['vault.items'];
    }

    public function tableColumns(string $id, $user): array
    {
        return app(Tables::class)->columns($id, $user);
    }

    public function tableDefaults(string $id): array
    {
        return app(Tables::class)->defaults($id);
    }
}
