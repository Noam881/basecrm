<?php

namespace Awove\Assets;

use Awove\Assets\Models\Assignment;
use Awove\Core\Models\EmployeeReference;
use Awove\Core\Services\EmployeeReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Assignments
{
    public function create($u, int $assetId, array $input): Assignment
    {
        return DB::transaction(function () use ($u, $assetId, $input) {
            $u = Access::fresh($u);
            $service = app(Records::class);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($assetId);
            Access::edit($u, $asset);
            abort_unless($u->can('assets.assign'), 403);
            $service->version($asset, $input, 'asset_version');
            if (! in_array($asset->status, ['available', 'in-use'], true) || $service->hasAssignment($asset)) {
                throw ValidationException::withMessages(['assignment' => 'Choose an Available or In use asset with no current assignment.']);
            }
            $input = Records::clean($input);
            $kind = $input['target_type'] ?? null;
            $data = validator($input, ['target_type' => ['required', Rule::in(['employee', 'customer', 'location'])], 'employee_reference_id' => $kind === 'employee' ? 'required|integer|min:1' : 'nullable|prohibited', 'customer_relationship_id' => $kind === 'customer' ? 'required|integer|min:1' : 'nullable|prohibited', 'location' => $kind === 'location' ? 'required|string|max:180' : 'nullable|prohibited', 'assigned_on' => 'required|date_format:Y-m-d|before_or_equal:'.Records::today(), 'expected_return_on' => 'nullable|date_format:Y-m-d|after_or_equal:assigned_on', 'notes' => 'nullable|string|max:10000'])->validate();
            $data = [...['employee_reference_id' => null, 'customer_relationship_id' => null, 'location' => null, 'expected_return_on' => null, 'notes' => null], ...$data];
            if ($kind === 'employee') {
                $id = (int) $data['employee_reference_id'];
                app(EmployeeReferences::class)->assertSelectable($u, $id);
                $employee = EmployeeReference::whereKey($id)->lockForUpdate()->firstOrFail();
                if (! $employee->active) {
                    throw ValidationException::withMessages(['employee_reference_id' => 'Choose an active employee reference.']);
                }
                $data['employee_reference_id'] = $id;
            }
            if ($kind === 'customer') {
                $data['customer_relationship_id'] = (int) $data['customer_relationship_id'];
                Relations::select($u, $data['customer_relationship_id'], 'customer');
            }
            $record = Assignment::create([...$data, 'asset_id' => $assetId, 'created_by' => $u->id, 'version' => 1]);
            $asset->update(['status' => 'in-use', 'version' => $asset->version + 1]);
            $service->history($u, $asset, 'assignment.created', array_keys($data), $record->id);

            return $record;
        });
    }

    public function update($u, int $id, array $input): Assignment
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $service = app(Records::class);
            $existing = Access::assignments($u)->findOrFail($id);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($existing->asset_id);
            Access::edit($u, $asset);
            abort_unless($u->can('assets.assign'), 403);
            $service->version($asset, $input, 'asset_version');
            $record = Assignment::whereKey($id)->lockForUpdate()->firstOrFail();
            $service->version($record, $input);
            abort_unless($record->returned_on === null, 409, 'A returned assignment retains its recorded dates and notes.');
            $input = Records::clean($input);
            $data = validator($input, ['expected_return_on' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:'.$record->toArray()['assigned_on'], 'notes' => 'sometimes|nullable|string|max:10000'])->validate();
            $record->fill($data);
            $fields = array_keys($record->getDirty());
            if (! $fields) {
                return $record;
            }
            $record->version++;
            $record->save();
            $asset->update(['version' => $asset->version + 1]);
            $service->history($u, $asset, 'assignment.updated', $fields, $record->id);

            return $record;
        });
    }

    public function return($u, int $id, array $input): Assignment
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $service = app(Records::class);
            $existing = Access::assignments($u)->findOrFail($id);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($existing->asset_id);
            Access::edit($u, $asset);
            abort_unless($u->can('assets.assign'), 403);
            $service->version($asset, $input, 'asset_version');
            $record = Assignment::whereKey($id)->lockForUpdate()->firstOrFail();
            $service->version($record, $input);
            abort_unless($record->returned_on === null, 409, 'This assignment was already returned.');
            $data = validator(Records::clean($input), ['returned_on' => 'required|date_format:Y-m-d|after_or_equal:'.$record->toArray()['assigned_on'].'|before_or_equal:'.Records::today(), 'return_condition' => 'required|string|max:10000'])->validate();
            $record->update([...$data, 'returned_by' => $u->id, 'version' => $record->version + 1]);
            $asset->update(['status' => $service->hasProgress($asset) ? 'under-maintenance' : 'available', 'version' => $asset->version + 1]);
            $service->history($u, $asset, 'assignment.returned', ['returned_on', 'return_condition'], $record->id);

            return $record;
        });
    }
}
