<?php

namespace Awove\Finance\History;

use Awove\Core\Imports\Operation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Illuminate\Database\Eloquent\Builder;

class Records
{
    public const KINDS = ['collection', 'income', 'bonus', 'monthly-hours', 'ancillary-quote', 'reference'];

    public const PLACEMENTS = ['linked', 'internal', 'unresolved'];

    public const PAGE_SIZE = 10;

    public const PREVIEW_BYTES = 8192;

    /** @return Builder<Record> */
    public function query($actor): Builder
    {
        return Access::scope($actor);
    }

    public function describe($actor, int $id): array
    {
        $record = Access::view($actor, $id);
        $identities = [];
        foreach ($record->identities()->orderBy('id')->get() as $link) {
            $type = $link->contact_id !== null ? 'contacts' : 'companies';
            $model = $type === 'contacts' ? Contact::class : Company::class;
            $identity = $model::findOrFail($link->contact_id ?? $link->company_id);
            $identities[$type.':'.$identity->id] = ['type' => $type, 'id' => $identity->id, 'name' => $identity->getAttribute('name'), 'profile_url' => '/people/'.$type.'/'.$identity->id];
        }
        $actorClass = config('auth.providers.users.model');
        $owner = $record->owner_id ? $actorClass::find($record->owner_id) : null;

        return ['id' => $record->id, 'kind' => $record->kind, 'placement' => $record->placement, 'owner' => $owner?->only(['id', 'name']),
            'identities' => array_values($identities), 'label' => $this->label($record), 'created_at' => $record->created_at->toISOString()];
    }

    public function page($actor, int $id, ?int $before = null): array
    {
        $record = Access::view($actor, $id);
        abort_unless($before === null || $before > 0, 422, 'Invalid history cursor.');
        $entries = $record->entries()->when($before, fn ($q) => $q->where('id', '<', $before))->orderByDesc('id')->limit(self::PAGE_SIZE + 1)->get();
        $more = $entries->count() > self::PAGE_SIZE;
        $entries = $entries->take(self::PAGE_SIZE);

        return ['entries' => $entries->map(fn (Entry $entry) => $this->dto($entry, false))->values()->all(), 'next_cursor' => $more ? $entries->last()->id : null];
    }

    public function entry($actor, int $id, int $entryId): array
    {
        $record = Access::view($actor, $id);

        return ['entry' => $this->dto($record->entries()->findOrFail($entryId), true)];
    }

    public function label(Record $record): ?string
    {
        $entry = $record->entries()->where('entry_kind', 'facts')->orderByDesc('id')->first();

        return $entry?->payload['label'] ?? null;
    }

    private function dto(Entry $entry, bool $full): array
    {
        $payload = $entry->payload;
        $operation = Operation::findOrFail($entry->operation_id);
        abort_unless($operation->state === 'verified' && $operation->mapping_id === $entry->source_mapping_id && $operation->result_target_type === 'finance.history-entry'
            && $operation->result_target_id === (string) $entry->id && hash_equals($operation->result_fingerprint, Fingerprints::entry($entry)), 503, 'Historical finance entry is unavailable.');
        $actor = config('auth.providers.users.model')::find($operation->actor_id);
        $remaining = $full ? PHP_INT_MAX : self::PREVIEW_BYTES;
        $text = mb_strcut($payload['text'], 0, $remaining, 'UTF-8');
        $remaining -= strlen($text);
        $more = strlen($text) < strlen($payload['text']);
        $facts = [];
        foreach ($payload['facts'] as $fact) {
            $display = Payload::display($fact);
            $preview = $display === null ? null : mb_strcut($display, 0, $remaining, 'UTF-8');
            $cut = $display !== null && strlen($preview) < strlen($display);
            $remaining -= strlen($preview ?? '');
            $more = $more || $cut;
            $facts[] = [...array_intersect_key($fact, array_flip(['key', 'label', 'value_kind', 'json_type', 'availability', 'unit', 'currency'])),
                'display' => $preview];
        }

        return ['id' => $entry->id, 'entry_kind' => $entry->entry_kind, 'source_revision' => $entry->source_revision,
            'label' => $payload['label'], 'facts' => $facts, 'text' => $text,
            'author' => $payload['author'], 'source_created_at' => $payload['source_created_at'], 'source_updated_at' => $payload['source_updated_at'],
            'parent_entry_id' => $entry->parent_entry_id, 'ingested_by' => $actor?->only(['id', 'name']), 'ingested_at' => $operation->finished_at->toISOString(),
            'source_media_present' => $payload['source_media_present'], 'has_more_text' => $more,
            'full_text_url' => $more ? '/finance/history/'.$entry->record_id.'/entries/'.$entry->id : null];
    }
}
