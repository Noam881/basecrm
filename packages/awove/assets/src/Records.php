<?php

namespace Awove\Assets;

use Awove\Assets\Models\Asset;
use Awove\Assets\Models\AssetDocument;
use Awove\Assets\Models\AssetHistory;
use Awove\Assets\Models\Assignment;
use Awove\Assets\Models\MaintenanceRecord;
use Awove\Core\Installation;
use Awove\Core\Services\DocumentLinks;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Records
{
    public static function moduleLabel(): string
    {
        $label = Installation::manifest()['module_labels']['assets'] ?? __('Assets');
        if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
            throw new \RuntimeException('Assets module label must be non-empty text of at most 100 characters.');
        }

        return $label;
    }

    public static function statuses(): array
    {
        return self::labels(['available' => 'Available', 'in-use' => 'In use', 'under-maintenance' => 'Under maintenance', 'retired' => 'Retired'], 'assets_statuses');
    }

    public static function maintenanceStatuses(): array
    {
        return self::labels(['planned' => 'Planned', 'in-progress' => 'In progress', 'completed' => 'Completed'], 'assets_maintenance_statuses');
    }

    private static function labels(array $defaults, string $key): array
    {
        $labels = Installation::manifest()[$key] ?? [];
        if (! is_array($labels)) {
            throw new \RuntimeException('Asset display labels must be a map.');
        }
        foreach (array_intersect_key($labels, $defaults) as $label) {
            if (! is_string($label) || trim($label) === '' || mb_strlen($label) > 100) {
                throw new \RuntimeException('Asset display labels must be non-empty text of at most 100 characters.');
            }
        }

        return array_replace(array_map(fn ($label) => (string) __($label), $defaults), array_intersect_key($labels, $defaults));
    }

    public static function clean(array $input): array
    {
        foreach ($input as $field => $value) {
            if (is_string($value)) {
                $input[$field] = trim($value) === '' ? null : trim($value);
            }
        }

        return $input;
    }

    public static function today(): string
    {
        return now(Installation::settings()['timezone'])->toDateString();
    }

    public function version(Model $r, array $input, string $field = 'version'): void
    {
        $data = validator($input, [$field => 'required|integer|min:1'])->validate();
        abort_unless((int) $data[$field] === $r->getAttribute('version'), 409, __('This asset or its work changed. Reload before saving.'));
    }

    public function owner($u, ?int $id, ?Asset $asset = null, ?int $existing = null, bool $maintenance = false): void
    {
        if ($existing === $id && ($asset !== null || $maintenance)) {
            return;
        }
        abort_unless($id === $u->id || $u->can('assets.assign'), 403);
        if ($id === null) {
            if ($maintenance) {
                throw ValidationException::withMessages(['owner_id' => __('Choose responsible staff for maintenance.')]);
            }

            return;
        }
        $owner = config('auth.providers.users.model')::where('active', true)->find($id);
        if (! Access::available($owner) || ($maintenance && $asset && ! Access::query($owner)->whereKey($asset->id)->exists())) {
            throw ValidationException::withMessages(['owner_id' => __('Choose active staff who already have access to this asset. Assignment does not grant access.')]);
        }
    }

    public function history($u, Asset $asset, string $event, array $fields = [], ?int $assignment = null, ?int $maintenance = null): void
    {
        AssetHistory::create(['asset_id' => $asset->id, 'assignment_id' => $assignment, 'maintenance_id' => $maintenance, 'actor_id' => $u->id, 'event' => $event, 'changed_fields' => $fields]);
    }

    public function hasAssignment(Asset $asset): bool
    {
        return Assignment::where('asset_id', $asset->id)->whereNull('returned_on')->exists();
    }

    public function hasProgress(Asset $asset, ?int $except = null): bool
    {
        return MaintenanceRecord::where('asset_id', $asset->id)->whereNull('archived_at')->where('status', 'in-progress')->when($except, fn ($q) => $q->whereKeyNot($except))->exists();
    }

    public function status(Asset $asset, string $status, ?int $completing = null, bool $completion = false): void
    {
        $assigned = $this->hasAssignment($asset);
        $progress = $this->hasProgress($asset, $completing);
        $field = $completion ? 'asset_status' : 'status';
        if ($progress && $status !== 'under-maintenance') {
            throw ValidationException::withMessages([$field => __('Other maintenance remains in progress; retain Under maintenance.')]);
        }
        if ($status === 'available' && $assigned) {
            throw ValidationException::withMessages([$field => __('An assigned asset must remain In use or Under maintenance.')]);
        }
        if ($completion && $status === 'in-use' && ! $assigned) {
            throw ValidationException::withMessages(['asset_status' => __('Choose Available or Under maintenance when no assignment is open.')]);
        }
        if ($status === 'retired' && ($assigned || MaintenanceRecord::where('asset_id', $asset->id)->whereNull('archived_at')->where('status', '!=', 'completed')->exists())) {
            throw ValidationException::withMessages(['status' => __('Return open assignments and complete or explicitly archive abandoned maintenance before retiring this asset.')]);
        }
    }

    public function save($u, array $input, ?int $id = null): Asset
    {
        try {
            return DB::transaction(function () use ($u, $input, $id) {
                $u = Access::fresh($u);
                $input = self::clean($input);
                $asset = $id ? Access::query($u)->lockForUpdate()->findOrFail($id) : null;
                if ($asset) {
                    Access::edit($u, $asset);
                    $this->version($asset, $input);
                } else {
                    abort_unless(Access::available($u) && $u->can('assets.create'), 403);
                }
                $values = [...($asset?->getAttributes() ?? ['owner_id' => $u->id, 'status' => 'available']), ...$input];
                $data = validator($values, ['code' => 'required|string|max:80', 'name' => 'required|string|max:180', 'type' => 'required|string|max:120', 'location' => 'required|string|max:180', 'owner_id' => 'nullable|integer|min:1', 'notes' => 'nullable|string|max:10000', 'purchased_on' => 'nullable|date_format:Y-m-d', 'serial_number' => 'nullable|string|max:180', 'status' => ['required', Rule::in(array_keys(self::statuses()))]])->validate();
                if (Asset::whereRaw('lower(code)=lower(?)', [$data['code']])->when($asset, fn ($q) => $q->whereKeyNot($asset->id))->exists()) {
                    throw ValidationException::withMessages(['code' => __('This asset ID already exists. Choose a unique ID.')]);
                }
                $data['owner_id'] = isset($data['owner_id']) ? (int) $data['owner_id'] : null;
                $this->owner($u, $data['owner_id'], $asset, $asset?->owner_id);
                if ($asset) {
                    $this->status($asset, $data['status']);
                    $asset->fill($data);
                    $fields = array_keys($asset->getDirty());
                    if (! $fields) {
                        return $asset;
                    }
                    $asset->version++;
                    $asset->save();
                } else {
                    $asset = Asset::create([...$data, 'created_by' => $u->id, 'version' => 1]);
                    $fields = array_keys(array_filter($data, fn ($v) => $v !== null));
                }
                $this->history($u, $asset, $id ? 'asset.updated' : 'asset.created', $fields);

                return $asset;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'assets_register_code_unique')) {
                throw ValidationException::withMessages(['code' => __('This asset ID already exists. Choose a unique ID.')]);
            }
            throw $e;
        }
    }

    public function archive($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $asset);
            abort_unless($u->can('assets.archive'), 403);
            $this->version($asset, $input);
            if ($this->hasAssignment($asset) || MaintenanceRecord::where('asset_id', $id)->whereNull('archived_at')->where('status', '!=', 'completed')->exists()) {
                throw ValidationException::withMessages(['asset' => __('Return current assignments and complete or explicitly archive abandoned maintenance before archiving this asset.')]);
            }
            $asset->update(['archived_at' => now(), 'version' => $asset->version + 1]);
            $this->history($u, $asset, 'asset.archived', ['archived_at']);
        });
    }

    public function document($u, int $id, array $input): AssetDocument
    {
        return DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            abort_unless($u->can('assets.documents.view') && $u->can('assets.documents.manage'), 403);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($id);
            Access::edit($u, $asset);
            $data = validator(self::clean($input), ['name' => 'required|string|max:180', 'url' => 'required|string|max:2048'])->validate();
            $data['url'] = app(DocumentLinks::class)->validate($data['url'], 'url');
            $doc = AssetDocument::create([...$data, 'asset_id' => $id, 'created_by' => $u->id, 'version' => 1]);
            $asset->update(['version' => $asset->version + 1]);
            $this->history($u, $asset, 'document.added', ['documents']);

            return $doc;
        });
    }

    public function archiveDocument($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            abort_unless($u->can('assets.documents.view') && $u->can('assets.documents.manage'), 403);
            $doc = AssetDocument::findOrFail($id);
            $asset = Access::query($u)->lockForUpdate()->findOrFail($doc->asset_id);
            Access::edit($u, $asset);
            $doc = AssetDocument::lockForUpdate()->findOrFail($id);
            $this->version($doc, $input);
            abort_if($doc->archived_at !== null, 403);
            $doc->update(['archived_at' => now(), 'version' => $doc->version + 1]);
            $asset->update(['version' => $asset->version + 1]);
            $this->history($u, $asset, 'document.archived', ['documents']);
        });
    }
}
