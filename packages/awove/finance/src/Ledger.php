<?php

namespace Awove\Finance;

use App\Models\User;
use Awove\Core\Installation;
use Awove\Core\Money;
use Awove\Core\Services\DocumentLinks;
use Awove\Core\Services\IdentityRelationships;
use Awove\Finance\Models\LedgerHistory;
use Awove\Finance\Models\LedgerRecord;
use Awove\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Ledger
{
    public static function kind(string $cap): string
    {
        return match ($cap) {
            'collections' => 'charge','expenses' => 'expense',default => abort(404)
        };
    }

    public static function role(string $cap): string
    {
        return $cap === 'collections' ? 'customer' : 'supplier';
    }

    /** @return Builder<LedgerRecord> */
    public function query($user, string $cap): Builder
    {
        return Access::scope(LedgerRecord::where('kind', self::kind($cap)), $user, $cap);
    }

    public function find($user, string $cap, int $id): LedgerRecord
    {
        return $this->query($user, $cap)->findOrFail($id);
    }

    public function create($user, string $cap, array $input): LedgerRecord
    {
        return DB::transaction(function () use ($user, $cap, $input) {
            $user = Access::actor($user, $cap);
            Currency::assertConsistent();
            abort_unless($user->can('finance.'.$cap.'.create'), 403);
            $data = $this->validate($user, $cap, $input);
            $record = LedgerRecord::create([...$data, 'kind' => self::kind($cap), 'created_by' => $user->id, 'currency' => Installation::settings()['currency']]);
            $this->historyEntry($record, $user, 'record.created', ['after' => $data]);

            return $record;
        });
    }

    public function update($user, string $cap, int $id, array $input): LedgerRecord
    {
        return DB::transaction(function () use ($user, $cap, $id, $input) {
            $user = Access::actor($user, $cap);
            Currency::assertConsistent();
            $record = $this->query($user, $cap)->lockForUpdate()->findOrFail($id);
            Access::edit($user, $record, $cap);
            $this->version($record, $input);
            abort_if($record->cancelled_at !== null, 403, __('Cancelled records are retained read only.'));
            $this->currency($record);
            $data = $this->validate($user, $cap, $input, $record);
            if (Payment::where('record_id', $id)->exists() && ($data['total'] !== $record->total || $data['relationship_id'] !== $record->relationship_id)) {
                throw ValidationException::withMessages(['total' => __('Payments already exist. Changing the financial total or customer/supplier requires an agreed correction policy and is unsupported.')]);
            }
            $before = $record->only(array_keys($data));
            $record->update([...$data, 'version' => $record->version + 1]);
            $this->historyEntry($record, $user, 'record.updated', ['before' => $before, 'after' => $record->only(array_keys($data))]);

            return $record;
        });
    }

    public function payment($user, string $cap, int $id, array $input): Payment
    {
        return DB::transaction(function () use ($user, $cap, $id, $input) {
            $user = Access::actor($user, $cap);
            Currency::assertConsistent();
            $record = $this->query($user, $cap)->lockForUpdate()->findOrFail($id);
            Access::edit($user, $record, $cap);
            abort_unless($user->can('finance.'.$cap.'.record-payment'), 403);
            $this->currency($record);
            $data = validator($input, ['version' => 'required|integer|min:1', 'amount' => 'required', 'paid_on' => 'required|date_format:Y-m-d', 'reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:10000', 'submission_key' => 'required|uuid'])->validate();
            $data['amount'] = Money::amount($data['amount'], 'amount', true);
            $data['reference'] = filled($data['reference'] ?? null) ? trim($data['reference']) : null;
            $data['notes'] = filled($data['notes'] ?? null) ? trim($data['notes']) : null;
            $fingerprint = hash('sha256', json_encode(array_intersect_key($data, array_flip(['amount', 'paid_on', 'reference', 'notes'])), JSON_THROW_ON_ERROR));
            $existing = Payment::where('record_id', $id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_fingerprint, $fingerprint), 409, __('This payment key records different input.'));

                return $existing;
            }
            $this->version($record, $data);
            if ($record->cancelled_at) {
                throw ValidationException::withMessages(['amount' => __('Cancelled records cannot receive payments.')]);
            }if (Money::compare($data['amount'], $this->remaining($record)) > 0) {
                throw ValidationException::withMessages(['amount' => __('Payment exceeds the outstanding balance. Overpayments, credits and refunds are unsupported.')]);
            }
            $payment = Payment::create(['record_id' => $id, 'actor_id' => $user->id, ...array_intersect_key($data, array_flip(['amount', 'paid_on', 'reference', 'notes', 'submission_key'])), 'submission_fingerprint' => $fingerprint]);
            $record->update(['version' => $record->version + 1]);
            $this->historyEntry($record, $user, 'payment.recorded', ['payment_id' => $payment->id, 'amount' => $payment->amount, 'paid_on' => $data['paid_on'], 'reference' => $data['reference'], 'notes' => $data['notes']]);

            return $payment;
        });
    }

    public function cancel($user, string $cap, int $id, array $input): LedgerRecord
    {
        return DB::transaction(function () use ($user, $cap, $id, $input) {
            $user = Access::actor($user, $cap);
            Currency::assertConsistent();
            $record = $this->query($user, $cap)->lockForUpdate()->findOrFail($id);
            Access::edit($user, $record, $cap);
            abort_unless($user->can('finance.'.$cap.'.cancel'), 403);
            $this->version($record, $input);
            $data = validator($input, ['cancellation_reason' => 'required|string|max:10000'])->validate();
            if (Payment::where('record_id', $id)->exists()) {
                throw ValidationException::withMessages(['cancellation_reason' => __('Payments already exist. Cancellation after payment requires an agreed refund/credit policy and is unsupported.')]);
            }if (! $record->cancelled_at) {
                $record->update(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancellation_reason' => $data['cancellation_reason'], 'version' => $record->version + 1]);
                $this->historyEntry($record, $user, 'record.cancelled', ['reason' => $data['cancellation_reason']]);
            }

            return $record;
        });
    }

    public function paid(LedgerRecord $record): string
    {
        return Money::sum([Payment::where('record_id', $record->id)->selectRaw('COALESCE(SUM(amount),0)::text AS paid')->value('paid')]);
    }

    public function remaining(LedgerRecord $record): string
    {
        return Money::subtract($record->total, $this->paid($record));
    }

    public function status(LedgerRecord $record, ?string $paid = null): string
    {
        if ($record->cancelled_at) {
            return 'cancelled';
        }$paid ??= $this->paid($record);

        return Money::compare($paid, '0.00') === 0 ? 'unpaid' : (Money::compare($paid, $record->total) === 0 ? 'paid' : 'part-paid');
    }

    public function relationship($user, string $cap, ?int $id): ?array
    {
        $service = app(IdentityRelationships::class);
        $record = $id ? $service->query($user, self::role($cap), true)->find($id) : null;

        return $record ? $service->describe($user, $record) : null;
    }

    public function describe($user, string $cap, LedgerRecord $record): array
    {
        $user = Access::actor($user, $cap);
        $this->viewRecord($user, $cap, $record);
        $relation = $this->relationship($user, $cap, $record->relationship_id);
        $paid = $this->paid($record);

        return [...$record->toArray(), 'relationship_id' => $relation ? $record->relationship_id : null, 'relationship' => $relation, 'owner' => $this->person($record->owner_id), 'paid_amount' => $paid, 'remaining_amount' => Money::subtract($record->total, $paid), 'status' => $this->status($record, $paid), 'editable' => ! $record->cancelled_at && ($user->can('finance.'.$cap.'.edit.all') || ($record->owner_id === $user->id && $user->can('finance.'.$cap.'.edit.own'))), 'document_link' => $record->document_url ? '/finance/'.$cap.'/'.$record->id.'/document' : null];
    }

    public function payments($user, string $cap, LedgerRecord $record): array
    {
        $this->viewRecord($user, $cap, $record);

        return Payment::where('record_id', $record->id)->orderByDesc('paid_on')->orderByDesc('id')->get()->map(fn (Payment $p) => [...$p->only(['id', 'amount', 'reference', 'notes']), 'paid_on' => $p->paid_on->toDateString(), 'actor' => $this->person($p->actor_id)])->all();
    }

    public function history($user, string $cap, LedgerRecord $record): array
    {
        $this->viewRecord($user, $cap, $record);

        return LedgerHistory::where('record_id', $record->id)->latest('id')->get()->map(function (LedgerHistory $entry) use ($user, $cap) {
            $details = $entry->details ?? [];
            $changed = [];
            foreach ($details['after'] ?? [] as $key => $value) {
                if (! array_key_exists($key, $details['before'] ?? []) || $details['before'][$key] !== $value) {
                    $changed[] = $key;
                }
            }foreach (['before', 'after'] as $side) {
                if (isset($details[$side]['relationship_id']) && ! $this->relationship($user, $cap, $details[$side]['relationship_id'])) {
                    $details[$side]['relationship_id'] = null;
                }
            }

            return [...$entry->only(['id', 'event', 'version']), 'details' => $details, 'changed_fields' => $changed, 'actor' => $this->person($entry->actor_id), 'created_at' => $entry->created_at->toISOString()];
        })->all();
    }

    private function validate($user, string $cap, array $input, ?LedgerRecord $record = null): array
    {
        $data = validator($input, ['relationship_id' => 'nullable|integer|min:1', 'reference' => 'required|string|max:120', 'description' => 'required|string|max:20000', 'category' => ($cap === 'expenses' ? 'required' : 'nullable').'|string|max:120', 'total' => 'required', 'currency' => 'prohibited', 'owner_id' => 'nullable|integer|min:1', 'due_on' => 'required|date_format:Y-m-d', 'document_url' => 'nullable|string|max:2048', 'next_action' => 'nullable|string|max:10000', 'next_action_on' => 'nullable|date_format:Y-m-d', 'status' => 'prohibited', 'paid_amount' => 'prohibited', 'remaining_amount' => 'prohibited'])->validate();
        $data = [...['relationship_id' => $record?->relationship_id, 'owner_id' => $record ? $record->owner_id : $user->id, 'category' => null, 'document_url' => null, 'next_action' => null, 'next_action_on' => null], ...$data];
        $data['relationship_id'] = filled($data['relationship_id']) ? (int) $data['relationship_id'] : $record?->relationship_id;
        if (! $data['relationship_id']) {
            throw ValidationException::withMessages(['relationship_id' => __('Choose an existing active :role classification.', ['role' => __(ucfirst(self::role($cap)))])]);
        }if (! $record || $data['relationship_id'] !== $record->relationship_id) {
            $relationship = app(IdentityRelationships::class)->query($user, self::role($cap))->where('active', true)->find($data['relationship_id']);
            abort_unless($relationship !== null, 403);
        }
        $data['total'] = Money::amount($data['total'], 'total', true);
        $data['document_url'] = app(DocumentLinks::class)->validate($data['document_url']);
        $data['owner_id'] = filled($data['owner_id']) ? (int) $data['owner_id'] : null;
        if ((! $record && $data['owner_id'] !== $user->id) || ($record && $record->owner_id !== $data['owner_id'])) {
            abort_unless($user->can('finance.'.$cap.'.assign'), 403);
        }if ($data['owner_id'] && (! $record || $record->owner_id !== $data['owner_id'])) {
            $owner = User::find($data['owner_id']);
            abort_unless(Access::available($owner, $cap), 403);
        }
        if ($cap !== 'collections') {
            $data['next_action'] = null;
            $data['next_action_on'] = null;
        } elseif (filled($data['next_action']) !== filled($data['next_action_on'])) {
            throw ValidationException::withMessages(['next_action' => __('Enter both the next collection action and its date, or leave both blank.')]);
        }

        return $data;
    }

    private function viewRecord($user, string $cap, LedgerRecord $record): void
    {
        abort_unless($record->kind === self::kind($cap), 403);
        Access::view($user, $record, $cap);
    }

    private function version(LedgerRecord $record, array $input): void
    {
        $data = validator($input, ['version' => 'required|integer|min:1'])->validate();
        abort_unless($record->version === (int) $data['version'], 409, __('This financial record changed. Reload before saving.'));
    }

    private function currency(LedgerRecord $record): void
    {
        if ($record->currency !== Installation::settings()['currency']) {
            throw ValidationException::withMessages(['currency' => __('The installation currency differs from this retained record. Cross-currency changes are unsupported.')]);
        }
    }

    private function historyEntry(LedgerRecord $record, $user, string $event, array $details): void
    {
        LedgerHistory::create(['record_id' => $record->id, 'actor_id' => $user->id, 'event' => $event, 'version' => $record->version, 'details' => $details]);
    }

    private function person(?int $id): ?array
    {
        return $id ? User::find($id)?->only(['id', 'name']) : null;
    }
}
