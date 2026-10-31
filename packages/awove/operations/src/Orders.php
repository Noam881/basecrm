<?php

namespace Awove\Operations;

use Awove\Core\Currency;
use Awove\Core\Installation;
use Awove\Core\Services\DocumentLinks;
use Awove\Operations\Models\OrderHistory;
use Awove\Operations\Models\OrderReceipt;
use Awove\Operations\Models\SupplierOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Orders
{
    public const STATUSES = ['draft' => 'Draft', 'ordered' => 'Ordered', 'part-received' => 'Part received', 'received' => 'Received', 'cancelled' => 'Cancelled'];

    public function create($user, array $input): SupplierOrder
    {
        $user = $this->fresh($user);
        abort_unless(Access::available($user) && $user->can('operations.create'), 403);
        $data = $this->validate($user, $input);

        return DB::transaction(function () use ($user, $data) {
            app(Currency::class)->assertConsistent();
            $order = SupplierOrder::create([...$data, 'created_by' => $user->id, 'currency' => Currency::current()]);
            $this->recordHistory($order, $user, 'order.created', ['after' => $data]);

            return $order;
        });
    }

    public function update($user, int $id, array $input): SupplierOrder
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $user = $this->fresh($user);
            $order = SupplierOrder::lockForUpdate()->findOrFail($id);
            Access::edit($user, $order);
            $this->version($order, $input);
            app(Currency::class)->assertConsistent();
            $data = $this->validate($user, $input, $order);
            $before = $order->only(['supplier_relationship_id', 'owner_id', 'reference', 'description', 'quantity', 'agreed_total', 'status', 'ordered_on', 'expected_on', 'document_url', 'outstanding_items']);
            $order->update([...$data, 'version' => $order->version + 1]);
            $this->recordHistory($order, $user, 'order.updated', ['before' => $before, 'after' => $order->only(array_keys($before))]);

            return $order;
        });
    }

    public function receipt($user, int $id, array $input): OrderReceipt
    {
        $data = validator($input, ['version' => 'required|integer|min:1', 'received_on' => 'required|date_format:Y-m-d', 'quantity' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/D'], 'complete' => 'required|boolean', 'description' => 'required|string|max:10000', 'outstanding_items' => 'nullable|string|max:10000', 'submission_key' => 'required|uuid'])->validate();
        $data['quantity'] = filled($data['quantity'] ?? null) ? Quantity::format(Quantity::units((string) $data['quantity'])) : null;
        $data['complete'] = (bool) $data['complete'];
        $data['outstanding_items'] = filled($data['outstanding_items'] ?? null) ? trim($data['outstanding_items']) : null;
        $data['description'] = trim($data['description']);
        $fingerprint = hash('sha256', json_encode(array_intersect_key($data, array_flip(['received_on', 'quantity', 'complete', 'description', 'outstanding_items'])), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $id, $data, $fingerprint) {
            $user = $this->fresh($user);
            $order = SupplierOrder::lockForUpdate()->findOrFail($id);
            Access::edit($user, $order);
            $existing = OrderReceipt::where('order_id', $id)->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->submission_fingerprint, $fingerprint), 409, 'This receipt key already records different input.');

                return $existing;
            }
            $this->version($order, $data);
            if (in_array($order->status, ['draft', 'received', 'cancelled'])) {
                throw ValidationException::withMessages(['received_on' => 'Record receipts only for an ordered or partially received order.']);
            }
            if ($data['received_on'] < $order->ordered_on->toDateString()) {
                throw ValidationException::withMessages(['received_on' => 'Receipt date cannot be before the order date.']);
            }
            $received = $this->receivedUnits($order);
            if ($order->quantity !== null) {
                if ($data['quantity'] === null || Quantity::units($data['quantity']) <= 0) {
                    throw ValidationException::withMessages(['quantity' => 'Enter the received quantity.']);
                }
                $received += Quantity::units($data['quantity']);
                if ($received > Quantity::units($order->quantity)) {
                    throw ValidationException::withMessages(['quantity' => 'Received quantity exceeds the agreed outstanding quantity.']);
                }
                $complete = $received === Quantity::units($order->quantity);
            } else {
                if ($data['quantity'] !== null) {
                    throw ValidationException::withMessages(['quantity' => 'This order has no agreed quantity. Describe the received items and choose partial or complete.']);
                }
                $complete = $data['complete'];
                if (! $complete && ! filled($data['outstanding_items'] ?? null)) {
                    throw ValidationException::withMessages(['outstanding_items' => 'Describe the outstanding items for a partial receipt.']);
                }
            }
            $receipt = OrderReceipt::create(['order_id' => $id, 'actor_id' => $user->id, 'received_on' => $data['received_on'], 'quantity' => $data['quantity'], 'complete' => $complete, 'description' => $data['description'], 'submission_key' => $data['submission_key'], 'submission_fingerprint' => $fingerprint]);
            $order->update(['status' => $complete ? 'received' : 'part-received', 'received_on' => $complete ? $data['received_on'] : null, 'outstanding_items' => $complete ? null : ($data['outstanding_items'] ?? $order->outstanding_items), 'version' => $order->version + 1]);
            $this->recordHistory($order, $user, 'receipt.recorded', ['receipt_id' => $receipt->id, 'received_on' => $data['received_on'], 'quantity' => $receipt->quantity, 'complete' => $complete, 'description' => $data['description'], 'outstanding_items' => $order->outstanding_items]);

            return $receipt;
        });
    }

    public function archive($user, int $id, array $input): SupplierOrder
    {
        return DB::transaction(function () use ($user, $id, $input) {
            $user = $this->fresh($user);
            $order = SupplierOrder::lockForUpdate()->findOrFail($id);
            Access::edit($user, $order);
            abort_unless($user->can('operations.archive'), 403);
            $this->version($order, $input);
            $order->update(['archived_at' => now(), 'version' => $order->version + 1]);
            $this->recordHistory($order, $user, 'order.archived');

            return $order;
        });
    }

    public function receivedUnits(SupplierOrder $order): int
    {
        $total = OrderReceipt::where('order_id', $order->id)->selectRaw('COALESCE(SUM(quantity),0)::text AS total')->value('total');

        return Quantity::units($total);
    }

    public function describe($user, SupplierOrder $order): array
    {
        Access::view($user, $order);
        $supplier = Relations::describe($user, $order->supplier_relationship_id, 'supplier');
        $received = $order->quantity !== null ? $this->receivedUnits($order) : null;

        return [...$order->toArray(), 'supplier_relationship_id' => $supplier ? $order->supplier_relationship_id : null, 'supplier' => $supplier, 'owner' => $this->person($order->owner_id), 'received_quantity' => $received !== null ? Quantity::format($received) : null, 'outstanding_quantity' => $received !== null ? Quantity::format(Quantity::units($order->quantity) - $received) : null, 'editable' => ! $order->archived_at && ($user->can('operations.edit.all') || ($order->owner_id === $user->id && $user->can('operations.edit.own'))), 'overdue' => $order->expected_on && $order->expected_on->toDateString() < now(Installation::settings()['timezone'])->toDateString() && in_array($order->status, ['ordered', 'part-received']), 'document_link' => $order->document_url ? '/operations/orders/'.$order->id.'/document' : null];
    }

    public function history($user, SupplierOrder $order): array
    {
        Access::view($user, $order);

        return OrderHistory::where('order_id', $order->id)->orderByDesc('id')->get()->map(function ($entry) use ($user) {
            $details = $entry->details ?? [];
            $changed = [];
            foreach ($details['after'] ?? [] as $key => $value) {
                if (! array_key_exists($key, $details['before'] ?? []) || $details['before'][$key] !== $value) {
                    $changed[] = $key;
                }
            }
            foreach (['before', 'after'] as $key) {
                if (isset($details[$key]['supplier_relationship_id']) && ! Relations::describe($user, $details[$key]['supplier_relationship_id'], 'supplier')) {
                    $details[$key]['supplier_relationship_id'] = null;
                }
            }

            return [...$entry->only(['id', 'event']), 'details' => $details, 'changed_fields' => $changed, 'actor' => $this->person($entry->actor_id), 'created_at' => $entry->created_at->toISOString()];
        })->all();
    }

    public function receipts($user, SupplierOrder $order): array
    {
        Access::view($user, $order);

        return OrderReceipt::where('order_id', $order->id)->orderByDesc('received_on')->orderByDesc('id')->get()->map(fn ($receipt) => [...$receipt->only(['id', 'description', 'quantity', 'complete']), 'received_on' => $receipt->received_on->toDateString(), 'actor' => $this->person($receipt->actor_id)])->all();
    }

    private function validate($user, array $input, ?SupplierOrder $order = null): array
    {
        $data = validator($input, ['supplier_relationship_id' => 'nullable|integer|min:1', 'owner_id' => 'nullable|integer|min:1', 'reference' => 'required|string|max:120', 'description' => 'required|string|max:20000', 'quantity' => ['nullable', 'string', 'regex:/^(0|[1-9][0-9]{0,10})(?:\.[0-9]{1,3})?$/D'], 'agreed_total' => ['required', 'string', 'regex:/^(0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D'], 'ordered_on' => 'required|date_format:Y-m-d', 'expected_on' => 'nullable|date_format:Y-m-d', 'document_url' => 'nullable|url:https|max:2000', 'outstanding_items' => 'nullable|string|max:10000', 'status' => ['required', Rule::in(array_keys(self::STATUSES))]])->validate();
        $data = [...['supplier_relationship_id' => $order?->supplier_relationship_id, 'owner_id' => $order ? $order->owner_id : $user->id, 'quantity' => null, 'expected_on' => null, 'document_url' => null, 'outstanding_items' => null], ...$data];
        $data['supplier_relationship_id'] = filled($data['supplier_relationship_id']) ? (int) $data['supplier_relationship_id'] : $order?->supplier_relationship_id;
        if (! $data['supplier_relationship_id']) {
            throw ValidationException::withMessages(['supplier_relationship_id' => 'Choose an existing active supplier classification.']);
        }
        Relations::authorize($user, $data['supplier_relationship_id'], 'supplier', $order?->supplier_relationship_id);
        $data['owner_id'] = filled($data['owner_id']) ? (int) $data['owner_id'] : null;
        if ((! $order && $data['owner_id'] !== $user->id) || ($order && $order->owner_id !== $data['owner_id'])) {
            abort_unless($user->can('operations.assign'), 403);
        }
        if ($data['owner_id'] && (! $order || $order->owner_id !== $data['owner_id'])) {
            $owner = config('auth.providers.users.model')::findOrFail($data['owner_id']);
            abort_unless($owner->active && ($owner->can('operations.view.own') || $owner->can('operations.view.all')), 403);
        }
        if ($data['quantity'] !== null) {
            $data['quantity'] = Quantity::format(Quantity::units((string) $data['quantity']));
            if (Quantity::units($data['quantity']) <= 0) {
                throw ValidationException::withMessages(['quantity' => 'Agreed quantity must be greater than zero, or left blank.']);
            }
        }
        $hasReceipts = $order && OrderReceipt::where('order_id', $order->id)->exists();
        if ($hasReceipts) {
            $firstReceiptDate = OrderReceipt::where('order_id', $order->id)->min('received_on');
            if ($data['ordered_on'] > $firstReceiptDate) {
                throw ValidationException::withMessages(['ordered_on' => 'Order date cannot be after an already recorded receipt.']);
            }
            if ($data['supplier_relationship_id'] !== $order->supplier_relationship_id || $data['quantity'] !== $order->quantity) {
                throw ValidationException::withMessages(['quantity' => 'Supplier and agreed quantity cannot be changed after recording receipts.']);
            }
            if (! in_array($data['status'], [$order->status, 'cancelled'])) {
                throw ValidationException::withMessages(['status' => 'Recorded receipts determine this order status. Receipt history cannot be reset.']);
            }
        } elseif (in_array($data['status'], ['part-received', 'received'])) {
            throw ValidationException::withMessages(['status' => 'Use Record receipt to record partial or complete delivery.']);
        }
        $data['document_url'] = app(DocumentLinks::class)->validate($data['document_url']);

        return $data;
    }

    private function version(SupplierOrder $order, array $input): void
    {
        $version = validator($input, ['version' => 'required|integer|min:1'])->validate()['version'];
        abort_unless((int) $version === $order->version, 409, 'This order changed. Reload before saving.');
    }

    private function recordHistory(SupplierOrder $order, $user, string $event, array $details = []): void
    {
        OrderHistory::create(['order_id' => $order->id, 'actor_id' => $user->id, 'event' => $event, 'details' => $details]);
    }

    private function fresh($user)
    {
        $user = $user?->fresh();
        abort_unless($user && $user->active, 403);

        return $user;
    }

    private function person(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id)?->only(['id', 'name']) : null;
    }
}
