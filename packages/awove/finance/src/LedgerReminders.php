<?php

namespace Awove\Finance;

use App\Models\User;
use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\Core\Modules;
use Awove\Finance\Models\LedgerRecord;

class LedgerReminders implements ReminderSource
{
    public function __construct(private string $cap) {}

    public function module(): string
    {
        return 'finance';
    }

    public function key(): string
    {
        return $this->cap;
    }

    public function due(): iterable
    {
        if (! app(Modules::class)->enabled('finance:'.$this->cap)) {
            return;
        }
        $query = LedgerRecord::where('kind', Ledger::kind($this->cap))->whereNull('cancelled_at')->whereNotNull('owner_id')->whereRaw(Tables::remainingExpression().'>0')->whereRaw(($this->cap === 'collections' ? 'COALESCE(next_action_on,due_on)' : 'due_on').' <= ?', [now(Installation::settings()['timezone'])->toDateString()]);
        foreach ($query->cursor() as $record) {
            if ($this->current($record->id, $record->owner_id)) {
                yield ['record_id' => $record->id, 'version' => $record->version, 'user_id' => $record->owner_id];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $user = User::find($recipientId);
        if (! Access::available($user, $this->cap)) {
            return null;
        }$record = app(Ledger::class)->query($user, $this->cap)->where('owner_id', $recipientId)->whereNull('cancelled_at')->whereRaw(Tables::remainingExpression().'>0')->whereRaw(($this->cap === 'collections' ? 'COALESCE(next_action_on,due_on)' : 'due_on').' <= ?', [now(Installation::settings()['timezone'])->toDateString()])->find($recordId);

        return $record ? ['version' => $record->version, 'title' => ($this->cap === 'collections' ? 'Collection action: ' : 'Supplier payment due: ').$record->reference, 'href' => '/finance/'.$this->cap.'/'.$record->id] : null;
    }
}
