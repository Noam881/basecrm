<?php

namespace Awove\CustomerService;

use Awove\Core\Contracts\ReminderSource;
use Awove\Core\Installation;
use Awove\CustomerService\Models\Feedback;
use Awove\CustomerService\Models\ServiceRequest;

class ActionReminders implements ReminderSource
{
    public function __construct(private string $source) {}

    public function module(): string
    {
        return 'customer-service';
    }

    public function key(): string
    {
        return $this->source;
    }

    public function due(): iterable
    {
        $model = $this->source === 'requests' ? ServiceRequest::class : Feedback::class;
        foreach ($model::whereNull('archived_at')->whereNotNull('owner_id')->cursor() as $record) {
            if ($this->current($record->id, $record->owner_id)) {
                yield ['record_id' => $record->id, 'version' => $record->version, 'user_id' => $record->owner_id];
            }
        }
    }

    public function current(int $recordId, int $recipientId): ?array
    {
        $user = config('auth.providers.users.model')::find($recipientId);
        $model = $this->source === 'requests' ? ServiceRequest::class : Feedback::class;
        $record = Access::records($user, $model)->whereNull('archived_at')->where('owner_id', $recipientId)->find($recordId);
        if (! $record) {
            return null;
        }
        $date = $this->source === 'requests' ? $record->due_on : $record->follow_up_on;
        $today = now(Installation::settings()['timezone'])->toDateString();
        $open = $this->source === 'requests' ? ! in_array($record->status, ['resolved', 'closed']) : ($record->action_required && ! $record->followed_up_on);
        if (! $date || $date->toDateString() > $today || ! $open) {
            return null;
        }

        return ['version' => $record->version, 'title' => $this->source === 'requests' ? __('Service action: :subject', ['subject' => $record->subject]) : __('Customer feedback follow-up #:id', ['id' => $record->id]), 'href' => $this->source === 'requests' ? '/service/requests/'.$record->id : '/service/feedback/'.$record->id];
    }
}
