<?php

namespace Awove\Assets;

use Awove\Assets\Models\MaintenanceCompletion;
use Awove\Assets\Models\MaintenanceCost;
use Awove\Assets\Models\MaintenanceRecord;
use Awove\Core\Currency;
use Awove\Core\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Maintenance
{
    public function save($u, int $assetId, array $input, ?int $id = null): MaintenanceRecord
    {
        return DB::transaction(function () use ($u, $assetId, $input, $id) {
            $u = Access::fresh($u);
            $service = app(Records::class);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($assetId);
            Access::edit($u, $asset);
            $service->version($asset, $input, 'asset_version');
            $record = $id ? Access::maintenance($u)->where('asset_id', $assetId)->lockForUpdate()->findOrFail($id) : null;
            if ($record) {
                abort_if($record->archived_at !== null, 403);
                $service->version($record, $input);
            } else {
                abort_unless($u->can('assets.create'), 403);
                if ($asset->status === 'retired') {
                    throw ValidationException::withMessages(['asset' => __('Reactivate a retired asset explicitly before planning new maintenance.')]);
                }
            }
            $input = Records::clean($input);
            $values = [...($record?->getAttributes() ?? ['owner_id' => $u->id, 'status' => 'planned']), ...$input];
            $rules = $record?->status === 'completed' ? ['owner_id' => 'required|integer|min:1', 'description' => 'nullable|string|max:10000', 'next_due_on' => 'nullable|date_format:Y-m-d|after:'.$record->toArray()['completed_on']] : ['action' => 'required|string|max:180', 'description' => 'nullable|string|max:10000', 'owner_id' => 'required|integer|min:1', 'supplier_relationship_id' => 'nullable|integer|min:1', 'due_on' => 'required|date_format:Y-m-d', 'status' => ['required', Rule::in(['planned', 'in-progress'])], 'next_due_on' => 'nullable|prohibited'];
            if ($record?->status === 'completed') {
                foreach (['action', 'due_on', 'status', 'supplier_relationship_id', 'completed_on', 'cost'] as $f) {
                    if (array_key_exists($f, $input) && $input[$f] !== $record->getAttribute($f)) {
                        throw ValidationException::withMessages([$f => __('The completed action retains its original details. Edit only responsible staff, description or the manual next reminder date.')]);
                    }
                }
            }
            $data = validator($values, $rules)->validate();
            $data['owner_id'] = (int) $data['owner_id'];
            $service->owner($u, $data['owner_id'], $asset, $record?->owner_id, true);
            if (array_key_exists('supplier_relationship_id', $data)) {
                $old = $record?->getAttribute('supplier_relationship_id');
                $next = isset($data['supplier_relationship_id']) ? (int) $data['supplier_relationship_id'] : null;
                if ($old && $next === null && Relations::summary($u, $old, 'supplier') === null) {
                    $next = $old;
                }
                if ($next !== $old) {
                    if ($old && Relations::summary($u, $old, 'supplier') === null) {
                        abort(403);
                    }
                    if ($next !== null) {
                        Relations::select($u, $next, 'supplier');
                    }
                }
                $data['supplier_relationship_id'] = $next;
            }
            if ($record) {
                $record->fill($data);
                $fields = array_keys($record->getDirty());
                if (! $fields) {
                    return $record;
                }
                $record->version++;
                $record->save();
            } else {
                $record = MaintenanceRecord::create([...$data, 'asset_id' => $assetId, 'created_by' => $u->id, 'version' => 1]);
                $fields = array_keys($data);
            }
            $changes = ['version' => $asset->version + 1];
            if ($record->status === 'in-progress') {
                $changes['status'] = 'under-maintenance';
            }
            $asset->update($changes);
            $service->history($u, $asset, $id ? 'maintenance.updated' : 'maintenance.created', $fields, maintenance: $record->id);

            return $record;
        });
    }

    public function complete($u, int $id, array $input): MaintenanceRecord
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $service = app(Records::class);
            $existing = Access::maintenance($u)->findOrFail($id);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($existing->asset_id);
            Access::edit($u, $asset);
            $service->version($asset, $input, 'asset_version');
            $record = MaintenanceRecord::lockForUpdate()->findOrFail($id);
            $service->version($record, $input);
            abort_if($record->archived_at !== null, 403);
            abort_if($record->status === 'completed', 409, __('This maintenance completion is already recorded.'));
            $data = validator(Records::clean($input), ['completed_on' => 'required|date_format:Y-m-d|before_or_equal:'.Records::today(), 'next_due_on' => 'nullable|date_format:Y-m-d|after:completed_on', 'asset_status' => ['required', Rule::in(['available', 'in-use', 'under-maintenance'])], 'cost' => 'nullable'])->validate();
            $service->status($asset, $data['asset_status'], $id, true);
            $cost = null;
            if (array_key_exists('cost', $data) && $data['cost'] !== null) {
                abort_unless($u->can('assets.costs.view') && $u->can('assets.costs.manage'), 403);
                $cost = Money::amount($data['cost'], 'cost');
                app(Currency::class)->assertConsistent();
            }
            $snapshot = $cost !== null ? MaintenanceCost::create(['maintenance_id' => $id, 'amount' => $cost, 'currency' => Currency::current(), 'actor_id' => $u->id]) : null;
            MaintenanceCompletion::create(['maintenance_id' => $id, 'cost_id' => $snapshot?->id, 'completed_on' => $data['completed_on'], 'next_due_on' => $data['next_due_on'] ?? null, 'actor_id' => $u->id]);
            $record->update(['status' => 'completed', 'completed_on' => $data['completed_on'], 'next_due_on' => $data['next_due_on'] ?? null, 'version' => $record->version + 1]);
            $asset->update(['status' => $data['asset_status'], 'version' => $asset->version + 1]);
            $service->history($u, $asset, 'maintenance.completed', ['status', 'completed_on', 'next_due_on', ...($cost !== null ? ['cost'] : [])], maintenance: $id);

            return $record;
        });
    }

    public function recordCost($u, int $id, array $input): MaintenanceRecord
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            abort_unless($u->can('assets.costs.view') && $u->can('assets.costs.manage'), 403);
            $service = app(Records::class);
            $existing = Access::maintenance($u)->findOrFail($id);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($existing->asset_id);
            Access::edit($u, $asset);
            $service->version($asset, $input, 'asset_version');
            $record = MaintenanceRecord::lockForUpdate()->findOrFail($id);
            $service->version($record, $input);
            abort_if($record->archived_at !== null, 403);
            if ($record->status !== 'completed') {
                throw ValidationException::withMessages(['cost' => __('Complete the maintenance action before recording its final manual cost.')]);
            }
            $data = validator(Records::clean($input), ['cost' => 'required'])->validate();
            $amount = Money::amount($data['cost'], 'cost');
            app(Currency::class)->assertConsistent();
            MaintenanceCost::create(['maintenance_id' => $id, 'amount' => $amount, 'currency' => Currency::current(), 'actor_id' => $u->id]);
            $record->update(['version' => $record->version + 1]);
            $asset->update(['version' => $asset->version + 1]);
            $service->history($u, $asset, 'maintenance.cost-recorded', ['cost'], maintenance: $id);

            return $record;
        });
    }

    public function archive($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $service = app(Records::class);
            $existing = Access::maintenance($u)->findOrFail($id);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($existing->asset_id);
            Access::edit($u, $asset);
            abort_unless($u->can('assets.archive'), 403);
            $service->version($asset, $input, 'asset_version');
            $record = MaintenanceRecord::lockForUpdate()->findOrFail($id);
            $service->version($record, $input);
            abort_if($record->archived_at !== null, 403);
            $record->update(['archived_at' => now(), 'version' => $record->version + 1]);
            $asset->update(['version' => $asset->version + 1]);
            $service->history($u, $asset, 'maintenance.archived', ['archived_at'], maintenance: $id);
        });
    }
}
