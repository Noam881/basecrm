<?php

namespace Awove\Assets;

use Awove\Assets\Models\Asset;
use Awove\Assets\Models\Assignment;
use Awove\Assets\Models\MaintenanceRecord;
use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Modules;

class DueReminders implements ReminderSource
{
    public function __construct(private string $section) {}

    public function module(): string
    {
        return 'assets';
    }

    public function key(): string
    {
        return $this->section;
    }

    private function dueQuery()
    {
        $table = $this->section === 'returns' ? 'assets_assignments' : 'assets_maintenance';
        $q = ($this->section === 'returns' ? Assignment::query() : MaintenanceRecord::query())->whereIn('asset_id', Asset::whereNull('archived_at')->where('status', '!=', 'retired')->select('id'));
        if ($this->section === 'returns') {
            return $q->whereNull('returned_on')->whereNotNull('expected_return_on')->where('expected_return_on', '<=', Records::today());
        }

        return $q->whereNull('archived_at')->whereRaw("(CASE WHEN $table.status='completed' THEN $table.next_due_on ELSE $table.due_on END) <= ?", [Records::today()]);
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('assets')) {
            return;
        }
        foreach ($this->dueQuery()->orderBy('id')->cursor() as $row) {
            $asset = Asset::find($row->asset_id);
            $recipient = $this->section === 'returns' ? $asset?->owner_id : $row->owner_id;
            if ($asset && $recipient) {
                yield ['record_id' => $row->id, 'version' => $asset->version, 'user_id' => $recipient];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $u = config('auth.providers.users.model')::where('active', true)->find($recipientId);
        if (! Access::available($u)) {
            return null;
        }
        $row = $this->dueQuery()->whereKey($recordId)->whereIn('asset_id', Access::query($u)->select('id'))->first();
        if (! $row) {
            return null;
        }
        $asset = Access::query($u)->find($row->asset_id);
        if (! $asset || ($this->section === 'returns' ? $asset->owner_id : $row->owner_id) !== $recipientId) {
            return null;
        }

        return ['version' => $asset->version, 'title' => $this->section === 'returns' ? __('Asset return due: :name', ['name' => $asset->name]) : __($row->status === 'completed' ? 'Next maintenance date due: :name — :action' : 'Maintenance due: :name — :action', ['name' => $asset->name, 'action' => $row->action]), 'href' => $this->section === 'returns' ? '/assets/register/'.$asset->id : '/assets/maintenance/'.$row->id];
    }
}
