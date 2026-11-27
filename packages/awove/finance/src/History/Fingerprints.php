<?php

namespace Awove\Finance\History;

/** Keyed ordinary metadata digests. Never returns raw source/customer values to the ledger. */
final class Fingerprints
{
    public static function digest(string $purpose, array $values): string
    {
        return hash_hmac('sha256', 'finance-source-history:v1:'.$purpose."\0".json_encode($values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), app('encrypter')->getKey());
    }

    public static function record(Record $record): string
    {
        return self::digest('record-target', [$record->getAttributes(), $record->identities()->orderBy('id')->get()->map(fn (Identity $identity) => $identity->getAttributes())->all()]);
    }

    public static function entry(Entry $entry): string
    {
        return self::digest('entry-target', [$entry->id, $entry->record_id, $entry->entry_kind, $entry->family_key, $entry->source_revision,
            $entry->previous_entry_id, $entry->parent_entry_id, $entry->source_mapping_id, $entry->operation_id, $entry->payload, $entry->created_at->toISOString()]);
    }
}
