<?php

namespace Awove\CustomerService;

use Awove\Core\Access as IdentityAccess;
use Awove\Core\Installation;
use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Services\Csv;
use Awove\Core\Services\Spreadsheet;
use Awove\Core\Tables\Query;
use Awove\CustomerService\Models\Feedback;
use Awove\CustomerService\Models\History;
use Awove\CustomerService\Models\ServiceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class ServiceController
{
    public const STATUSES = ['new' => 'New', 'in-progress' => 'In progress', 'waiting-customer' => 'Waiting for customer', 'resolved' => 'Resolved', 'closed' => 'Closed'];

    public const CHANNELS = ['phone' => 'Phone', 'email' => 'Email', 'message' => 'Message', 'website' => 'Website', 'in-person' => 'In person', 'other' => 'Other'];

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless(Access::available($user), 403);
        $this->filters($request);
        $query = $this->query($request);
        $feedback = $this->query($request, Feedback::class);
        $today = $this->today();
        $metrics = [
            'open' => (clone $query)->whereNotIn('status', ['resolved', 'closed'])->count(),
            'overdue' => (clone $query)->whereNotIn('status', ['resolved', 'closed'])->where('due_on', '<', $today)->count(),
            'awaiting' => (clone $query)->where('status', 'waiting-customer')->count(),
            'unassigned' => (clone $query)->whereNull('owner_id')->count(),
            'resolved' => (clone $query)->whereIn('status', ['resolved', 'closed'])->count(),
            'feedback_due' => (clone $feedback)->where('action_required', true)->whereNull('followed_up_on')->count(),
        ];
        $byStatus = (clone $query)->select('status')->selectRaw('COUNT(*) AS total')->groupBy('status')->pluck('total', 'status');
        $byOwner = (clone $query)->whereNotIn('status', ['resolved', 'closed'])->select('owner_id')->selectRaw('COUNT(*) AS total')->groupBy('owner_id')->get()->map(fn ($item) => ['owner' => $this->owner($item->owner_id), 'total' => (int) $item->getAttribute('total')]);
        $table = app(Query::class)->run($query, 'customer-service.requests', $request);
        $table['page']->through(function ($item) use ($user) {
            $data = $this->serialize($item, $user);
            $data['record_url'] = '/service/requests/'.$item->id;
            $data['record_link_column'] = 'subject';

            return $data;
        });
        $feedbackPage = $feedback->orderByDesc('received_on')->orderByDesc('id')->paginate(20, ['*'], 'feedback_page')->withQueryString();
        $feedbackPage->through(fn ($item) => $this->serialize($item, $user));

        return Inertia::render('CustomerService/Index', [...$this->options($user), 'table' => $table, 'feedback' => $feedbackPage, 'filters' => $request->only(['search', 'status', 'priority', 'owner_id', 'contact_id', 'company_id', 'from', 'to', 'view', 'date_field']), 'metrics' => $metrics, 'byStatus' => $byStatus, 'byOwner' => $byOwner]);
    }

    public function store(Request $request)
    {
        $user = $request->user();
        abort_unless(Access::available($user) && $user->can('customer-service.create'), 403);
        $data = $this->validateRequest($request, null);
        $record = DB::transaction(function () use ($data, $user) {
            $record = ServiceRequest::create([...$data, 'created_by' => $user->id]);
            $this->history($record, $user, 'created', 'Request created.', ['resolution' => $record->resolution, 'resolved_on' => $record->resolved_on?->toDateString(), 'document_url' => $record->document_url]);

            return $record;
        });

        return redirect('/service/requests/'.$record->id);
    }

    public function show(Request $request, int $id)
    {
        $record = ServiceRequest::findOrFail($id);
        Access::view($request->user(), $record);
        $history = History::where('request_id', $record->id)->orderByDesc('occurred_at')->orderByDesc('id')->get()->map(function ($item) {
            return [...$item->only(['id', 'event', 'kind', 'summary', 'details']), 'occurred_at' => $item->occurred_at->toISOString(), 'staff' => $this->owner($item->actor_id), 'document_link' => ! empty($item->details['document_url']) ? '/service/requests/'.$item->request_id.'/history/'.$item->id.'/document' : null];
        });

        return Inertia::render('CustomerService/Request', [...$this->options($request->user()), 'record' => $this->serialize($record, $request->user()), 'history' => $history]);
    }

    public function update(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $record = ServiceRequest::lockForUpdate()->findOrFail($id);
            Access::edit($request->user(), $record);
            $this->version($request, $record);
            $data = $this->validateRequest($request, $record);
            $previous = $record->status;
            $record->fill($data);
            $changed = array_keys($record->getDirty());
            $record->version++;
            $record->save();
            $event = $previous !== $record->status && in_array($previous, ['closed', 'resolved']) && ! in_array($record->status, ['closed', 'resolved']) ? 'reopened' : ($previous !== $record->status ? 'status_changed' : 'updated');
            $this->history($record, $request->user(), $event, $event === 'reopened' ? 'Request reopened; previous resolutions retained.' : 'Request updated.', ['from' => $previous, 'to' => $record->status, 'fields' => $changed, 'resolution' => in_array($record->status, ['resolved', 'closed']) ? $record->resolution : null, 'resolved_on' => $record->resolved_on?->toDateString(), 'document_url' => $record->document_url]);
        });

        return back();
    }

    public function interaction(Request $request, int $id)
    {
        $data = $request->validate(['kind' => ['required', Rule::in(['note', 'call', 'meeting', 'email', 'message'])], 'occurred_at' => ['required', 'date_format:Y-m-d\TH:i:sP'], 'summary' => ['required', 'string', 'max:10000']]);
        $data['occurred_at'] = CarbonImmutable::parse($data['occurred_at'])->utc();
        DB::transaction(function () use ($request, $id, $data) {
            $record = ServiceRequest::lockForUpdate()->findOrFail($id);
            Access::edit($request->user(), $record);
            History::create(['request_id' => $record->id, 'actor_id' => $request->user()->id, 'event' => 'interaction', ...$data]);
        });

        return back();
    }

    public function archive(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $record = ServiceRequest::lockForUpdate()->findOrFail($id);
            Access::edit($request->user(), $record);
            $this->version($request, $record);
            $record->update(['archived_at' => now(), 'version' => $record->version + 1]);
            $this->history($record, $request->user(), 'archived', 'Request archived.');
        });

        return redirect('/service');
    }

    public function document(Request $request, int $id)
    {
        $record = ServiceRequest::findOrFail($id);
        Access::view($request->user(), $record);
        abort_unless($record->document_url !== null && $record->document_url !== '', 404);
        $this->safeUrl($record->document_url);

        return redirect()->away($record->document_url)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function historicalDocument(Request $request, int $id, int $historyId)
    {
        $record = ServiceRequest::findOrFail($id);
        Access::view($request->user(), $record);
        $history = History::where('request_id', $record->id)->findOrFail($historyId);
        $url = $history->details['document_url'] ?? null;
        abort_unless(is_string($url) && $url !== '', 404);
        $this->safeUrl($url);

        return redirect()->away($url)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']);
    }

    public function storeFeedback(Request $request)
    {
        $user = $request->user();
        abort_unless(Access::available($user) && $user->can('customer-service.create'), 403);
        $data = $this->validateFeedback($request, null);
        $record = DB::transaction(function () use ($data, $user) {
            $record = Feedback::create([...$data, 'created_by' => $user->id]);
            $this->feedbackHistory($record, $user, 'feedback.created', 'Customer feedback recorded.');

            return $record;
        });

        return redirect('/service/feedback/'.$record->id);
    }

    public function showFeedback(Request $request, int $id)
    {
        $record = Feedback::findOrFail($id);
        Access::view($request->user(), $record);

        return Inertia::render('CustomerService/Feedback', [...$this->options($request->user()), 'record' => $this->serialize($record, $request->user()), 'history' => History::where('feedback_id', $record->id)->orderByDesc('occurred_at')->orderByDesc('id')->get()->map(fn ($item) => [...$item->only(['id', 'event', 'summary', 'details']), 'occurred_at' => $item->occurred_at->toISOString(), 'staff' => $this->owner($item->actor_id)])]);
    }

    public function updateFeedback(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $record = Feedback::lockForUpdate()->findOrFail($id);
            Access::edit($request->user(), $record);
            $this->version($request, $record);
            $data = $this->validateFeedback($request, $record);
            $before = $record->only(['rating', 'comments', 'action_required', 'follow_up_on', 'followed_up_on', 'owner_id']);
            $record->update([...$data, 'version' => $record->version + 1]);
            $this->feedbackHistory($record, $request->user(), 'feedback.updated', 'Customer feedback updated.', ['before' => $before, 'after' => $record->only(array_keys($before))]);
        });

        return back();
    }

    public function archiveFeedback(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $record = Feedback::lockForUpdate()->findOrFail($id);
            Access::edit($request->user(), $record);
            $this->version($request, $record);
            $record->update(['archived_at' => now(), 'version' => $record->version + 1]);
            $this->feedbackHistory($record, $request->user(), 'feedback.archived', 'Customer feedback archived.');
        });

        return redirect('/service');
    }

    public function export(Request $request)
    {
        abort_unless(Access::available($request->user()) && $request->user()->can('customer-service.export'), 403);
        $this->filters($request);
        $request->validate(['type' => ['nullable', Rule::in(['requests', 'feedback'])]]);
        $feedback = $request->input('type') === 'feedback';
        $query = $this->query($request, $feedback ? Feedback::class : ServiceRequest::class);
        if (! $feedback) {
            $query = app(Query::class)->filtered($query, 'customer-service.requests', $request);
        }
        $query->orderBy('id');

        return response()->streamDownload(function () use ($query, $request, $feedback) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_map(fn ($heading) => Csv::heading($heading), $feedback ? ['ID', 'Customer', 'Request ID', 'Owner', 'Received', 'Rating (1-5)', 'Comments', 'Action required', 'Follow-up date', 'Completed date'] : ['ID', 'Subject', 'Customer', 'Status', 'Priority', 'Owner', 'Received', 'Next action', 'Due', 'Resolution date']), escape: '');
            foreach ($query->cursor() as $record) {
                $data = $this->serialize($record, $request->user());
                $row = $feedback ? [$data['id'], $data['customer']['name'] ?? '', $data['request_id'] ?? '', $data['owner']['name'] ?? '', $data['received_on'], $data['rating'], $data['comments'], $data['action_required'] ? 'Yes' : 'No', $data['follow_up_on'] ?? '', $data['followed_up_on'] ?? ''] : [$data['id'], $data['subject'], $data['customer']['name'] ?? '', $this->statuses()[$data['status']] ?? $data['status'], $data['priority'], $data['owner']['name'] ?? '', $data['received_on'], $data['next_action'] ?? '', $data['due_on'] ?? '', $data['resolved_on'] ?? ''];
                fputcsv($out, array_map(fn ($value) => Spreadsheet::safeCell((string) $value), $row), escape: '');
            }
            fclose($out);
        }, $feedback ? 'service-feedback.csv' : 'service-requests.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function validateRequest(Request $request, ?ServiceRequest $record): array
    {
        $data = $request->validate([
            'subject' => 'required|string|max:180', 'description' => 'required|string|max:20000',
            'contact_id' => 'nullable|integer|exists:crm_contacts,id', 'company_id' => 'nullable|integer|exists:crm_companies,id',
            'received_on' => 'required|date_format:Y-m-d', 'channel' => ['required', Rule::in(array_keys(self::CHANNELS))],
            'owner_id' => 'nullable|integer|exists:users,id', 'status' => ['required', Rule::in(array_keys(self::STATUSES))],
            'priority' => ['required', Rule::in(['low', 'normal', 'high'])], 'next_action' => 'nullable|string|max:10000',
            'due_on' => 'nullable|date_format:Y-m-d', 'resolution' => 'nullable|string|max:20000', 'resolved_on' => 'nullable|date_format:Y-m-d',
            'document_url' => 'nullable|url:https|max:2000',
        ]);
        $data = [...['contact_id' => null, 'company_id' => null, 'owner_id' => $record ? $record->owner_id : $request->user()->id, 'due_on' => null, 'next_action' => null], ...$data];
        foreach (['contact_id', 'company_id', 'owner_id', 'request_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] ? (int) $data[$field] : null;
            }
        }
        $this->relations($request->user(), $data, $record);
        if (in_array($data['status'], ['resolved', 'closed'])) {
            $data['resolution'] = $data['resolution'] ?? $record?->resolution;
            if (! trim($data['resolution'] ?? '')) {
                throw ValidationException::withMessages(['resolution' => __('Describe the resolution before resolving or closing this request.')]);
            }
            $data['resolved_on'] = $data['resolved_on'] ?? $record?->resolved_on?->toDateString() ?? $this->today();
        } elseif (! $record) {
            $data['resolution'] = null;
            $data['resolved_on'] = null;
        } else {
            // Reopening never discards the previous resolution or its document.
            $data['resolution'] = $record->resolution;
            $data['resolved_on'] = $record->resolved_on?->toDateString();
        }
        if (! empty($data['document_url'])) {
            $this->safeUrl($data['document_url']);
        }

        return $data;
    }

    private function validateFeedback(Request $request, ?Feedback $record): array
    {
        $data = $request->validate([
            'contact_id' => 'nullable|integer|exists:crm_contacts,id', 'company_id' => 'nullable|integer|exists:crm_companies,id',
            'request_id' => 'nullable|integer|exists:service_requests,id', 'owner_id' => 'nullable|integer|exists:users,id',
            'received_on' => 'required|date_format:Y-m-d', 'rating' => 'required|integer|between:1,5', 'comments' => 'required|string|max:20000',
            'action_required' => 'required|boolean', 'follow_up_on' => 'nullable|date_format:Y-m-d', 'followed_up_on' => 'nullable|date_format:Y-m-d',
        ]);
        $data = [...['contact_id' => null, 'company_id' => null, 'request_id' => null, 'owner_id' => $record ? $record->owner_id : $request->user()->id, 'follow_up_on' => null, 'followed_up_on' => null], ...$data];
        foreach (['contact_id', 'company_id', 'owner_id', 'request_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $data[$field] ? (int) $data[$field] : null;
            }
        }
        $this->relations($request->user(), $data, $record);
        if ($record?->request_id && ! $data['request_id'] && ! Access::records($request->user())->whereKey($record->request_id)->exists()) {
            $data['request_id'] = $record->request_id;
        }
        if ($data['request_id']) {
            $related = ServiceRequest::findOrFail($data['request_id']);
            if (! $record || $record->request_id !== $data['request_id']) {
                Access::view($request->user(), $related);
            }
            if ($related->contact_id !== $data['contact_id'] || $related->company_id !== $data['company_id']) {
                throw ValidationException::withMessages(['request_id' => __('Feedback must use the same customer as the linked request.')]);
            }
        }
        if ($data['action_required'] && (! $data['owner_id'] || ! $data['follow_up_on'])) {
            throw ValidationException::withMessages(['follow_up_on' => __('Choose an owner and follow-up date when action is needed.')]);
        }

        return $data;
    }

    private function relations($user, array &$data, ServiceRequest|Feedback|null $record): void
    {
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $field => $model) {
            if ($record && $record->$field && ! $data[$field] && ! IdentityAccess::identities($user, $model)->whereKey($record->$field)->exists()) {
                $data[$field] = $record->$field;
            }
        }
        if (! $data['contact_id'] && ! $data['company_id']) {
            throw ValidationException::withMessages(['contact_id' => __('Select a contact or company.')]);
        }
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $field => $model) {
            if ($data[$field] && (! $record || (int) $data[$field] !== $record->$field)) {
                abort_unless(IdentityAccess::identities($user, $model)->whereKey($data[$field])->exists(), 403);
            }
        }
        $ownerId = $data['owner_id'] ? (int) $data['owner_id'] : null;
        if ((! $record && $ownerId !== $user->id) || ($record && $record->owner_id !== $ownerId)) {
            abort_unless($user->can('customer-service.assign'), 403);
        }
        if ($ownerId) {
            $owner = config('auth.providers.users.model')::findOrFail($ownerId);
            abort_unless($owner->active && ($owner->can('customer-service.view.own') || $owner->can('customer-service.view.all')), 403);
        }
    }

    private function serialize(ServiceRequest|Feedback $record, $user): array
    {
        $data = $record->toArray();
        $data['owner'] = $this->owner($record->owner_id);
        $data['customer'] = null;
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $field => $model) {
            $identity = $record->$field ? IdentityAccess::identities($user, $model)->find($record->$field) : null;
            if ($identity && ! $data['customer']) {
                $data['customer'] = ['id' => $identity->getKey(), 'type' => $field === 'contact_id' ? 'contacts' : 'companies', 'name' => $identity->getAttribute('name')];
            }
            // IDs for inaccessible identities are withheld too.
            if ($record->$field && ! $identity) {
                $data[$field] = null;
            }
        }
        if ($record instanceof Feedback && $record->request_id && ! Access::records($user)->whereKey($record->request_id)->exists()) {
            $data['request_id'] = null;
        }
        $data['editable'] = ! $record->archived_at && ($user->can('customer-service.edit.all') || ($record->owner_id === $user->id && $user->can('customer-service.edit.own')));
        $data['overdue'] = $record instanceof ServiceRequest && $record->due_on && $record->due_on->toDateString() < $this->today() && ! in_array($record->status, ['resolved', 'closed']);
        if ($record instanceof ServiceRequest) {
            // The stored link is visible only within this permission-scoped record.
            $data['document_link'] = $record->document_url ? '/service/requests/'.$record->id.'/document' : null;
        }

        return $data;
    }

    private function options($user): array
    {
        return [
            'statuses' => $this->statuses(), 'channels' => array_map(fn ($label) => (string) __($label), self::CHANNELS),
            'contacts' => IdentityAccess::identities($user, Contact::class)->orderBy('name')->get(['id', 'name']),
            'companies' => IdentityAccess::identities($user, Company::class)->orderBy('name')->get(['id', 'name']),
            'owners' => config('auth.providers.users.model')::where('active', true)->get(['id', 'name'])->filter(fn ($owner) => $owner->can('customer-service.view.own') || $owner->can('customer-service.view.all'))->values(),
            'relatedRequests' => Access::records($user)->whereNull('archived_at')->latest('id')->get(['id', 'subject', 'contact_id', 'company_id'])->map(function ($record) use ($user) {
                return $this->serialize($record, $user);
            }),
            'canCreate' => $user->can('customer-service.create'), 'canAssign' => $user->can('customer-service.assign'), 'canExport' => $user->can('customer-service.export'), 'canViewAll' => $user->can('customer-service.view.all'),
            'today' => $this->today(),
        ];
    }

    private function filters(Request $request): void
    {
        $request->validate(['search' => 'nullable|string|max:180', 'status' => ['nullable', Rule::in(array_keys(self::STATUSES))], 'priority' => ['nullable', Rule::in(['low', 'normal', 'high'])], 'owner_id' => 'nullable|integer', 'contact_id' => 'nullable|integer', 'company_id' => 'nullable|integer', 'from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', ...($request->filled('from') ? ['after_or_equal:from'] : [])], 'view' => ['nullable', Rule::in(['overdue', 'unassigned', 'waiting', 'archived', 'feedback-due'])], 'date_field' => ['nullable', Rule::in(['received_on', 'resolved_on'])]]);
        if ($request->input('view') === 'unassigned') {
            abort_unless($request->user()->can('customer-service.view.all'), 403);
        }
        foreach (['contact_id' => Contact::class, 'company_id' => Company::class] as $field => $model) {
            if ($request->filled($field)) {
                abort_unless(IdentityAccess::identities($request->user(), $model)->whereKey($request->integer($field))->exists(), 403);
            }
        }
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<TModel>  $model
     * @return Builder<TModel>
     */
    private function query(Request $request, string $model = ServiceRequest::class): Builder
    {
        $query = Access::records($request->user(), $model);
        $request->input('view') === 'archived' ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        foreach (['owner_id', 'contact_id', 'company_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->integer($field));
            }
        }
        $dateField = $model === ServiceRequest::class && $request->input('date_field') === 'resolved_on' ? 'resolved_on' : 'received_on';
        if ($request->filled('from')) {
            $query->where($dateField, '>=', $request->input('from'));
        }
        if ($request->filled('to')) {
            $query->where($dateField, '<=', $request->input('to'));
        }
        if ($request->filled('search')) {
            $query->where(function ($query) use ($request, $model) {
                foreach ($model === ServiceRequest::class ? ['subject', 'description', 'next_action'] : ['comments'] as $field) {
                    $query->orWhere($field, 'ilike', '%'.addcslashes($request->string('search')->toString(), '%_\\').'%');
                }
            });
        }
        if ($model === ServiceRequest::class) {
            if ($request->filled('status')) {
                $query->where('status', $request->input('status'));
            }
            if ($request->filled('priority')) {
                $query->where('priority', $request->input('priority'));
            }
            if ($request->input('view') === 'overdue') {
                $query->whereNotIn('status', ['resolved', 'closed'])->where('due_on', '<', $this->today());
            }
            if ($request->input('view') === 'unassigned') {
                $query->whereNull('owner_id');
            }
            if ($request->input('view') === 'waiting') {
                $query->where('status', 'waiting-customer');
            }
        } elseif ($request->input('view') === 'feedback-due') {
            $query->where('action_required', true)->whereNull('followed_up_on');
        }

        return $query;
    }

    private function version(Request $request, ServiceRequest|Feedback $record): void
    {
        $request->validate(['version' => 'required|integer|min:1']);
        abort_unless($request->integer('version') === $record->version, 409, __('This record changed. Reload before saving.'));
    }

    private function history(ServiceRequest $record, $user, string $event, string $summary, array $details = []): void
    {
        History::create(['request_id' => $record->id, 'actor_id' => $user->id, 'event' => $event, 'occurred_at' => now(), 'summary' => $summary, 'details' => $details]);
    }

    private function feedbackHistory(Feedback $record, $user, string $event, string $summary, array $details = []): void
    {
        History::create(['feedback_id' => $record->id, 'actor_id' => $user->id, 'event' => $event, 'occurred_at' => now(), 'summary' => $summary, 'details' => $details]);
    }

    private function owner(?int $id): ?array
    {
        return $id ? config('auth.providers.users.model')::find($id)?->only(['id', 'name']) : null;
    }

    private function statuses(): array
    {
        return array_replace(array_map(fn ($label) => (string) __($label), self::STATUSES), array_intersect_key(Installation::manifest()['customer_service_statuses'] ?? [], self::STATUSES));
    }

    private function today(): string
    {
        return now(Installation::settings()['timezone'])->toDateString();
    }

    private function safeUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['document_url' => __('Use an HTTPS document link without embedded credentials.')]);
        }
    }
}
