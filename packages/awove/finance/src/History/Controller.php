<?php

namespace Awove\Finance\History;

use Awove\Core\Models\Company;
use Awove\Core\Models\Contact;
use Awove\Core\Tables\Query;
use Awove\Finance\FinanceModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class Controller
{
    public function index(Request $request, Records $records)
    {
        $table = app(Query::class)->run($records->query($request->user()), 'finance.history', $request);
        $table['page']->through(fn ($row) => ['id' => $row->id, 'cells' => $row->getAttribute('cells'), 'record_url' => '/finance/history/'.$row->id, 'record_link_column' => 'label']);

        return Inertia::render('Finance/History', ['table' => $table, 'sections' => app(FinanceModule::class)->sections($request->user())]);
    }

    public function show(Request $request, int $id, Records $records)
    {
        return Inertia::render('Finance/HistoryRecord', ['record' => $records->describe($request->user(), $id), ...$records->page($request->user(), $id),
            'files' => app(Files\Files::class)->forRecord($request->user(), $id), 'sections' => app(FinanceModule::class)->sections($request->user())]);
    }

    public function entries(Request $request, int $id, Records $records)
    {
        $input = $request->validate(['before' => 'nullable|integer|min:1']);

        return response()->json($records->page($request->user(), $id, isset($input['before']) ? (int) $input['before'] : null))->header('Cache-Control', 'private, no-store');
    }

    public function entry(Request $request, int $id, int $entry, Records $records)
    {
        return response()->json($records->entry($request->user(), $id, $entry))->header('Cache-Control', 'private, no-store');
    }

    public function identity(Request $request, string $type, int $identity, Records $records)
    {
        $model = $type === 'contacts' ? Contact::class : Company::class;
        $parent = $model::findOrFail($identity);
        Gate::forUser(Access::actor($request->user()))->authorize('view', $parent);
        $input = $request->validate(['before' => 'nullable|integer|min:1']);
        $query = $records->query($request->user())->whereHas('identities', fn ($q) => $q->where($type === 'contacts' ? 'contact_id' : 'company_id', $identity));
        $rows = $query->when(isset($input['before']), fn ($q) => $q->where('id', '<', (int) $input['before']))->orderByDesc('id')->limit(11)->get();
        $more = $rows->count() > 10;
        $rows = $rows->take(10);

        return response()->json(['records' => $rows->map(fn ($row) => ['id' => $row->id, 'label' => $records->label($row), 'kind' => $row->kind,
            'record_url' => '/finance/history/'.$row->id, 'files' => app(Files\Files::class)->forRecord($request->user(), $row->id)])->values(),
            'next_cursor' => $more ? $rows->last()->id : null])->header('Cache-Control', 'private, no-store');
    }
}
