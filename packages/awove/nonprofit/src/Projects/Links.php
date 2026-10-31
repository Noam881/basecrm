<?php

namespace Awove\Nonprofit\Projects;

use Awove\Core\Currency;
use Awove\Finance\Models\Payment;
use Awove\Nonprofit\Projects\Models\PaymentLink;
use Awove\Nonprofit\Projects\Models\Project;
use Awove\Nonprofit\Projects\Models\ResourceLink;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class Links
{
    public function addResource($u, int $id, array $input): ResourceLink
    {
        return app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($input) {
            $data = validator($input, ['target_type' => ['required', Rule::in(['asset', 'operations-order'])], 'target_id' => 'required|integer|min:1'])->validate();
            $source = app(Connections::class)->resourceQuery($u, $data['target_type'])?->whereNull('archived_at')->lockForUpdate()->find($data['target_id']);
            abort_unless($source !== null && app(Connections::class)->resource($u, $data['target_type'], (int) $data['target_id'], true) !== null, 403);
            abort_if(ResourceLink::where('project_id', $p->id)->where('target_type', $data['target_type'])->where('target_id', $data['target_id'])->whereNull('archived_at')->exists(), 409, 'This resource is already linked.');
            $link = ResourceLink::create([...$data, 'project_id' => $p->id, 'actor_id' => $u->id]);
            app(Records::class)->change($u, $p, 'resource.linked', ['resources']);

            return $link;
        });
    }

    public function addPayment($u, int $id, array $input): PaymentLink
    {
        return app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($input) {
            abort_unless($u->can('nonprofit.projects.budgets.view') && $u->can('nonprofit.projects.budgets.manage'), 403);
            app(Currency::class)->assertConsistent();
            $data = validator($input, ['direction' => ['required', Rule::in(['incoming', 'outgoing'])], 'payment_id' => 'required|integer|min:1'])->validate();
            $payment = app(Connections::class)->payment($u, $data['direction'], (int) $data['payment_id']);
            abort_unless($payment !== null, 403);
            // Lock the immutable source payment too: whole-payment attribution cannot race across projects.
            Payment::whereKey($data['payment_id'])->lockForUpdate()->firstOrFail();
            if (PaymentLink::where('payment_id', $data['payment_id'])->whereNull('archived_at')->exists()) {
                throw ValidationException::withMessages(['payment_id' => 'This whole payment is already linked to a project. Splitting payments is unsupported.']);
            }
            $link = PaymentLink::create([...$data, 'project_id' => $p->id, 'actor_id' => $u->id]);
            app(Records::class)->change($u, $p, 'payment.linked', ['financial_links']);

            return $link;
        });
    }

    public function archiveResource($u, int $id, int $linkId, array $input): void
    {
        app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($linkId, $input) {
            $link = ResourceLink::where('project_id', $p->id)->lockForUpdate()->findOrFail($linkId);
            Access::version($link, $input);
            abort_if($link->archived_at !== null, 409);
            abort_unless(app(Connections::class)->resource($u, $link->target_type, $link->target_id) !== null, 403);
            $link->update(['archived_at' => now(), 'version' => $link->version + 1]);
            app(Records::class)->change($u, $p, 'resource.unlinked', ['resources']);
        });
    }

    public function archivePayment($u, int $id, int $linkId, array $input): void
    {
        app(Records::class)->mutate($u, $id, $input, function ($u, $p) use ($linkId, $input) {
            abort_unless($u->can('nonprofit.projects.budgets.view') && $u->can('nonprofit.projects.budgets.manage'), 403);
            $link = PaymentLink::where('project_id', $p->id)->lockForUpdate()->findOrFail($linkId);
            Access::version($link, $input);
            abort_if($link->archived_at !== null, 409);
            abort_unless(app(Connections::class)->payment($u, $link->direction, $link->payment_id) !== null, 403);
            $link->update(['archived_at' => now(), 'version' => $link->version + 1]);
            app(Records::class)->change($u, $p, 'payment.unlinked', ['financial_links']);
        });
    }

    public function resources($u, Project $p): array
    {
        Access::find($u, $p->id);

        return ResourceLink::where('project_id', $p->id)->whereNull('archived_at')->get()->map(function ($l) use ($u) {
            $target = app(Connections::class)->resource($u, $l->target_type, $l->target_id);

            return $target ? ['id' => $l->id, 'version' => $l->version, 'target' => $target] : ['restricted' => true];
        })->all();
    }

    public function payments($u, Project $p): array
    {
        Access::find($u, $p->id);
        if (! $u->fresh()->can('nonprofit.projects.budgets.view')) {
            return [];
        }

        return PaymentLink::where('project_id', $p->id)->whereNull('archived_at')->get()->map(function ($l) use ($u) {
            $target = app(Connections::class)->payment($u, $l->direction, $l->payment_id);

            return $target ? ['id' => $l->id, 'version' => $l->version, 'target' => $target] : ['restricted' => true];
        })->all();
    }
}
