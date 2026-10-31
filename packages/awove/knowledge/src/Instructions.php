<?php

namespace Awove\Knowledge;

use Awove\Core\Models\Routine;
use Awove\Core\Models\WorkItem;
use Awove\Core\Modules;
use Awove\Core\Services\Routines;
use Awove\Core\Services\WorkItems;
use Awove\Core\Services\WorkTypes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Instructions
{
    public function options($u): array
    {
        if (! Access::available($u, 'library')) {
            return [];
        }$items = [];
        foreach (array_keys(app(Modules::class)->installed()) as $module) {
            foreach (app(WorkTypes::class)->workTypes($module) as $type) {
                foreach (app(WorkItems::class)->query($u, $module, $type->kind)->whereNull('archived_at')->orderBy('id')->get() as $r) {
                    $items[] = ['key' => 'work:'.$r->id, 'type' => 'work', 'id' => $r->id, 'version' => $r->version, 'label' => $type->label.': '.$r->title];
                }
            }
            foreach (app(WorkTypes::class)->routineTypes($module) as $type) {
                foreach (app(Routines::class)->query($u, $module, $type->kind)->whereNull('archived_at')->orderBy('id')->get() as $r) {
                    $items[] = ['key' => 'routine:'.$r->id, 'type' => 'routine', 'id' => $r->id, 'version' => $r->version, 'label' => $type->label.': '.$r->name];
                }
            }
        }

        return $items;
    }

    public function reference($u, string $kind, int $id): ?array
    {
        $model = match ($kind) {
            'work' => WorkItem::class,'routine' => Routine::class,default => abort(404)
        };
        $raw = $model::find($id);
        if (! $raw) {
            return null;
        }
        $service = app($kind === 'work' ? WorkItems::class : Routines::class);
        $record = $service->query($u, $raw->module, $raw->kind)->whereNull('archived_at')->find($id);
        if (! $record) {
            return null;
        }
        $type = $kind === 'work' ? app(WorkTypes::class)->work($raw->module, $raw->kind) : app(WorkTypes::class)->routine($raw->module, $raw->kind);

        return $type ? ['label' => $record->getAttribute($kind === 'work' ? 'title' : 'name'), 'href' => $type->routePrefix.'/'.$id, 'version' => $record->version] : null;
    }

    public function forEntry($u, int $id): array
    {
        if (! Access::query($u, 'library')->whereKey($id)->exists()) {
            return [];
        }$result = [];
        foreach (DB::table('knowledge_instructions')->where('entry_id', $id)->whereNull('archived_at')->orderBy('id')->get() as $link) {
            $reference = $this->reference($u, $link->work_item_id ? 'work' : 'routine', $link->work_item_id ?? $link->routine_id);
            if ($reference) {
                $result[] = ['id' => $link->id, 'version' => $link->version, 'label' => $reference['label'], 'href' => $reference['href']];
            }
        }

        return $result;
    }

    public function forWork($u, WorkItem|Routine $record): array
    {
        $kind = $record instanceof WorkItem ? 'work' : 'routine';
        if (! $this->reference($u, $kind, $record->id)) {
            return [];
        }
        $q = Access::query($u, 'library')->whereNull('archived_at')->where('status', 'current')->whereIn('id', DB::table('knowledge_instructions')->where($kind === 'work' ? 'work_item_id' : 'routine_id', $record->id)->whereNull('archived_at')->select('entry_id'));

        return $q->orderBy('title')->get()->map(fn ($entry) => ['id' => 'knowledge.instructions.'.$entry->id, 'label' => $entry->title, 'href' => '/knowledge/library/'.$entry->id])->all();
    }

    public function add($u, int $entryId, array $input): void
    {
        DB::transaction(function () use ($u, $entryId, $input) {
            $u = Access::fresh($u);
            $entry = Access::query($u, 'library')->lockForUpdate()->findOrFail($entryId);
            Access::edit($u, $entry);
            app(Records::class)->version($entry, $input);
            $data = validator($input, ['reference_type' => ['required', Rule::in(['work', 'routine'])], 'reference_id' => 'required|integer|min:1', 'reference_version' => 'required|integer|min:1'])->validate();
            $field = $data['reference_type'] === 'work' ? 'work_item_id' : 'routine_id';
            $model = $data['reference_type'] === 'work' ? WorkItem::class : Routine::class;
            $parent = $model::lockForUpdate()->find($data['reference_id']);
            $reference = $this->reference($u, $data['reference_type'], $data['reference_id']);
            if (! $parent || ! $reference) {
                throw ValidationException::withMessages(['reference_id' => __('Choose work or a routine that is enabled and accessible to you.')]);
            }abort_unless($reference['version'] === (int) $data['reference_version'], 409, __('The linked work changed. Reload before linking.'));
            $existing = DB::table('knowledge_instructions')->where('entry_id', $entryId)->where($field, $parent->id)->first();
            if ($existing && ! $existing->archived_at) {
                throw ValidationException::withMessages(['reference_id' => __('This instruction is already linked to the selected work.')]);
            }
            if ($existing) {
                DB::table('knowledge_instructions')->where('id', $existing->id)->update(['archived_at' => null, 'version' => $existing->version + 1, 'updated_at' => now()]);
            } else {
                DB::table('knowledge_instructions')->insert(['entry_id' => $entryId, $field => $parent->id, 'created_by' => $u->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }$entry->update(['version' => $entry->version + 1]);
            app(Records::class)->history($u, $entry, 'instruction.linked', ['instructions']);
        });
    }

    public function archive($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            $link = DB::table('knowledge_instructions')->where('id', $id)->firstOrFail();
            $entry = Access::query($u, 'library')->lockForUpdate()->findOrFail($link->entry_id);
            Access::edit($u, $entry);
            app(Records::class)->version($entry, $input);
            abort_unless($this->reference($u, $link->work_item_id ? 'work' : 'routine', $link->work_item_id ?? $link->routine_id) !== null, 404);
            $data = validator($input, ['link_version' => 'required|integer|min:1'])->validate();
            abort_unless(! $link->archived_at && (int) $data['link_version'] === $link->version, 409);
            DB::table('knowledge_instructions')->where('id', $id)->update(['archived_at' => now(), 'version' => $link->version + 1, 'updated_at' => now()]);
            $entry->update(['version' => $entry->version + 1]);
            app(Records::class)->history($u, $entry, 'instruction.unlinked', ['instructions']);
        });
    }
}
