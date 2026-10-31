<?php

namespace Awove\Nonprofit\Fundraising;

use Awove\Core\Modules;
use Awove\Core\Money;
use Awove\Core\Services\IdentityRelationships;
use Awove\Finance\Ledger;
use Awove\Finance\Models\Payment;
use Awove\Nonprofit\Fundraising\Models\Constituent;
use Awove\Nonprofit\Fundraising\Models\Pledge;
use Awove\Nonprofit\Fundraising\Models\ReceiptLink;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only optional Finance adapter: expected charges never become actual receipts. */
class Receipts
{
    public function available($u): bool
    {
        $u = $u?->fresh();

        return Access::available($u) && $u->can(Access::PREFIX.'pledges.view') && $u->can(Access::PREFIX.'receipts.view') && app(Modules::class)->enabled('finance:collections') && class_exists(Ledger::class) && \Awove\Finance\Access::available($u, 'collections');
    }

    private function charge($u, Constituent $c, int $id)
    {
        if (! $this->available($u) || ! Access::query($u)->whereKey($c->id)->exists()) {
            return null;
        }
        $c = Access::query($u)->findOrFail($c->id);
        $donor = Access::relationships($u)->find($c->getAttribute('relationship_id'));
        if (! $donor) {
            return null;
        }
        $charge = app(Ledger::class)->query($u, 'collections')->find($id);
        if (! $charge || $charge->cancelled_at !== null) {
            return null;
        }
        $customer = app(IdentityRelationships::class)->query($u, 'customer', true)->find($charge->relationship_id);
        if (! $customer || $customer->contact_id !== $donor->contact_id || $customer->company_id !== $donor->company_id) {
            return null;
        }

        return $charge;
    }

    public function reference($u, Constituent $c, int $recordId, int $paymentId): ?array
    {
        $u = $u?->fresh();
        $charge = $this->charge($u, $c, $recordId);
        if (! $charge) {
            return null;
        }
        $payment = Payment::where('record_id', $charge->id)->find($paymentId);
        if (! $payment) {
            return null;
        }

        return ['payment_id' => $payment->id, 'finance_record_id' => $charge->id, 'finance_version' => $charge->version, 'label' => $charge->reference.' · '.$payment->paid_on->toDateString(), 'amount' => $payment->amount, 'currency' => $charge->currency, 'paid_on' => $payment->paid_on->toDateString(), 'href' => '/finance/collections/'.$charge->id];
    }

    public function options($u, Pledge $p): array
    {
        $u = $u?->fresh();
        if (! $this->available($u) || ! Access::pledges($u)->whereKey($p->id)->exists()) {
            return [];
        }
        $c = Access::query($u)->findOrFail($p->constituent_id);
        $result = [];
        $used = ReceiptLink::whereNull('archived_at')->pluck('payment_id')->all();
        foreach (app(Ledger::class)->query($u, 'collections')->whereNull('cancelled_at')->orderBy('id')->cursor() as $charge) {
            if (! $this->charge($u, $c, $charge->id)) {
                continue;
            }
            foreach (Payment::where('record_id', $charge->id)->whereNotIn('id', $used)->orderBy('paid_on')->get() as $payment) {
                $ref = $this->reference($u, $c, $charge->id, $payment->id);
                if ($ref) {
                    $result[] = $ref;
                }
            }
        }

        return $result;
    }

    public function forPledge($u, Pledge $p): array
    {
        $u = $u?->fresh();
        if (! Access::pledges($u)->whereKey($p->id)->exists() || ! $this->available($u)) {
            return ['available' => false, 'message' => 'Actual receipts are unavailable without current Finance and receipt permissions.'];
        }
        $c = Access::query($u)->findOrFail($p->constituent_id);
        $rows = [];
        $complete = true;
        $currencies = [];
        $amounts = [];
        foreach (ReceiptLink::where('pledge_id', $p->id)->whereNull('archived_at')->orderByDesc('id')->get() as $link) {
            $ref = $this->reference($u, $c, $link->getAttribute('finance_record_id'), $link->getAttribute('payment_id'));
            if (! $ref) {
                $complete = false;

                continue;
            }
            $rows[] = ['id' => $link->id, 'version' => $link->version, ...$ref];
            $currencies[] = $ref['currency'];
            $amounts[] = $ref['amount'];
        }
        $complete = $complete && count(array_diff(array_unique($currencies), [$p->getAttribute('currency')])) === 0;

        return ['available' => $complete, 'message' => $complete ? null : 'A linked receipt is unavailable or uses a different retained currency. A complete actual total cannot be shown.', 'amount' => $complete ? Money::sum($amounts) : null, 'currency' => $p->getAttribute('currency'), 'receipts' => $rows];
    }

    public function link($u, int $pledgeId, array $input): ReceiptLink
    {
        try {
            return DB::transaction(function () use ($u, $pledgeId, $input) {
                $u = Access::fresh($u);
                abort_unless($this->available($u) && $u->can(Access::PREFIX.'receipts.manage'), 403);
                $service = app(Records::class);
                $raw = Access::pledges($u)->findOrFail($pledgeId);
                $c = Access::query($u)->lockForUpdate()->findOrFail($raw->constituent_id);
                Access::edit($u, $c);
                $service->version($c, $input, 'constituent_version');
                $p = Access::pledges($u)->lockForUpdate()->findOrFail($pledgeId);
                abort_if($p->archived_at !== null, 403);
                $service->version($p, $input, 'pledge_version');
                $data = validator($input, ['finance_record_id' => 'required|integer|min:1', 'payment_id' => 'required|integer|min:1', 'finance_version' => 'required|integer|min:1'])->validate();
                $charge = app(Ledger::class)->query($u, 'collections')->lockForUpdate()->findOrFail($data['finance_record_id']);
                $ref = $this->reference($u, $c, $charge->id, (int) $data['payment_id']);
                abort_unless($ref !== null, 403);
                abort_unless($charge->version === (int) $data['finance_version'], 409, __('The Finance record changed. Reload available receipts.'));
                if ($ref['currency'] !== $p->getAttribute('currency')) {
                    throw ValidationException::withMessages(['payment_id' => __('Link receipts only in this pledge’s retained currency; no conversion is performed.')]);
                }
                if (ReceiptLink::where('payment_id', $ref['payment_id'])->whereNull('archived_at')->exists()) {
                    throw ValidationException::withMessages(['payment_id' => __('This actual payment already has an active pledge link.')]);
                }
                $link = ReceiptLink::create(['pledge_id' => $p->id, 'finance_record_id' => $charge->id, 'payment_id' => $ref['payment_id'], 'actor_id' => $u->id, 'version' => 1]);
                $p->update(['version' => $p->version + 1]);
                $c->update(['version' => $c->version + 1]);
                $service->history($u, $c, 'receipt.linked', ['receipts'], $p->id);

                return $link;
            });
        } catch (QueryException $e) {
            if ($e->getCode() === '23505' && str_contains($e->getMessage(), 'nonprofit_one_active_pledge_receipt')) {
                throw ValidationException::withMessages(['payment_id' => __('This actual payment already has an active pledge link.')]);
            }throw $e;
        }
    }

    public function archive($u, int $id, array $input): void
    {
        DB::transaction(function () use ($u, $id, $input) {
            $u = Access::fresh($u);
            abort_unless($this->available($u) && $u->can(Access::PREFIX.'receipts.manage'), 403);
            $service = app(Records::class);
            $raw = ReceiptLink::findOrFail($id);
            $p = Access::pledges($u)->findOrFail($raw->pledge_id);
            $c = Access::query($u)->lockForUpdate()->findOrFail($p->constituent_id);
            Access::edit($u, $c);
            $service->version($c, $input, 'constituent_version');
            $p = Access::pledges($u)->lockForUpdate()->findOrFail($p->id);
            abort_if($p->archived_at !== null, 403);
            $service->version($p, $input, 'pledge_version');
            $link = ReceiptLink::lockForUpdate()->findOrFail($id);
            $service->version($link, $input);
            abort_if($link->archived_at !== null, 409);
            abort_unless($this->reference($u, $c, $link->getAttribute('finance_record_id'), $link->getAttribute('payment_id')) !== null, 403);
            $link->update(['archived_at' => now(), 'version' => $link->version + 1]);
            $p->update(['version' => $p->version + 1]);
            $c->update(['version' => $c->version + 1]);
            $service->history($u, $c, 'receipt.unlinked', ['receipts'], $p->id);
        });
    }
}
