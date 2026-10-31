<?php

namespace Awove\Core\Services;

use Awove\Core\Models\Communication;
use Awove\Core\Models\Lead;
use Awove\Core\Models\Quotation;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class Sales
{
    public static function opportunityRules(): array
    {
        return [
            'needs' => 'sometimes|nullable|string|max:10000',
            'estimated_value' => 'sometimes|nullable|numeric|decimal:0,2|min:0|max:999999999999.99',
            'closing_date' => 'sometimes|nullable|date_format:Y-m-d',
            'final_value' => 'sometimes|nullable|numeric|decimal:0,2|min:0|max:999999999999.99',
            'loss_reason' => 'sometimes|nullable|string|max:10000',
        ];
    }

    public function details($user, Lead $lead): array
    {
        Gate::forUser($user)->authorize('view', $lead);

        return [
            'communications' => Communication::where('lead_id', $lead->id)->with('staff:id,name')->orderByDesc('occurred_at')->orderByDesc('id')->get(),
            'quotations' => Quotation::where('lead_id', $lead->id)->orderByDesc('id')->get()->map(fn (Quotation $quotation) => [
                ...$quotation->only(['id', 'number', 'amount', 'sent_date', 'status', 'proposal_id', 'document_url', 'accepted_at', 'version']),
                'sent_date' => $quotation->sent_date?->format('Y-m-d'),
                'accepted_at' => $quotation->accepted_at?->toISOString(),
                'download_url' => $quotation->proposal_id ? '/leads/'.$lead->id.'/proposals/'.$quotation->proposal_id.'/download' : null,
            ]),
        ];
    }

    public function communicate($user, Lead $lead, array $data): Communication
    {
        Gate::forUser($user)->authorize('update', $lead);
        $data = validator($data, [
            'type' => ['required', Rule::in(['call', 'meeting', 'email', 'message'])],
            'occurred_at' => 'required|date_format:Y-m-d\TH:i:sP',
            'summary' => 'required|string|max:10000',
        ])->validate();

        return DB::transaction(function () use ($user, $lead, $data) {
            $locked = Lead::lockForUpdate()->findOrFail($lead->id);
            Gate::forUser($user)->authorize('update', $locked);
            $communication = Communication::create([...$data, 'lead_id' => $locked->id, 'staff_id' => $user->id, 'occurred_at' => CarbonImmutable::parse($data['occurred_at'])->utc()]);
            app(Leads::class)->log($locked, $user, 'communication.recorded', ['communication_id' => $communication->id, 'type' => $communication->type]);

            return $communication;
        });
    }

    public function quotation($user, Lead $lead, array $data, ?Quotation $quotation = null): Quotation
    {
        Gate::forUser($user)->authorize('update', $lead);
        $data = validator($data, [
            'number' => 'required|string|max:100',
            'amount' => 'required|numeric|decimal:0,2|min:0|max:999999999999.99',
            'sent_date' => 'nullable|date_format:Y-m-d|required_if:status,sent,accepted',
            'status' => ['required', Rule::in(['draft', 'sent', 'accepted', 'rejected'])],
            'proposal_id' => ['nullable', 'integer', Rule::exists('crm_proposals', 'id')->where('lead_id', $lead->id)],
            'document_url' => 'nullable|url:http,https|max:2048',
            'version' => $quotation ? 'required|integer|min:1' : 'prohibited',
        ])->after(function ($validator) use ($data) {
            if (empty($data['proposal_id']) && empty($data['document_url'])) {
                $validator->errors()->add('document_url', 'Attach an uploaded proposal version or a document link.');
            }
            if (! empty($data['proposal_id']) && ! empty($data['document_url'])) {
                $validator->errors()->add('document_url', 'Choose one uploaded document or one document link.');
            }
        })->validate();

        return DB::transaction(function () use ($user, $lead, $data, $quotation) {
            $locked = Lead::lockForUpdate()->findOrFail($lead->id);
            Gate::forUser($user)->authorize('update', $locked);
            if ($quotation) {
                $quotation = Quotation::lockForUpdate()->findOrFail($quotation->id);
                abort_unless($quotation->lead_id === $locked->id, 404);
                abort_unless($quotation->version === (int) $data['version'], 409, 'This quotation changed. Reload before saving.');
                abort_if($quotation->status === 'accepted', 422, 'Accepted quotations are retained unchanged. Create a new quotation for a revision.');
            }
            unset($data['version']);
            $data['accepted_at'] = $data['status'] === 'accepted' ? now() : null;
            if ($quotation) {
                $quotation->fill([...$data, 'version' => $quotation->version + 1])->save();
            } else {
                $quotation = Quotation::create([...$data, 'lead_id' => $locked->id, 'created_by' => $user->id]);
            }
            app(Leads::class)->log($locked, $user, 'quotation.'.$quotation->status, ['quotation_id' => $quotation->id, 'version' => $quotation->version]);

            return $quotation;
        });
    }
}
