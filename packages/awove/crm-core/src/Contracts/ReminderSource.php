<?php

namespace Awove\Core\Contracts;

interface ReminderSource
{
    public function module(): string;

    public function key(): string;

    /** @return iterable<array{record_id:int, version:int, user_id:int}> */
    public function due(): iterable;

    /** Return a preview only while the work is due and the recipient can still access it.
     * @return array{version:int, title:string, href:string}|null
     */
    public function current(int $recordId, int $recipientId): ?array;
}
