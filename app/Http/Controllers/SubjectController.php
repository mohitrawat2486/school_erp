<?php

namespace App\Http\Controllers;

use App\Enums\SubjectStatus;
use App\Http\Requests\SaveSubjectRequest;
use App\Http\Requests\SubjectIndexRequest;
use App\Models\Subject;
use App\Services\SubjectService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SubjectController extends Controller
{
    public function index(SubjectIndexRequest $request): View
    {
        $filters = $request->validated();
        $query = Subject::query();
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(fn ($s) => $s->where('name', 'like', $p)->orWhere('code', 'like', $p));
        }
        foreach (['status', 'type'] as $field) {
            if ($value = $filters[$field] ?? null) {
                $query->where($field, $value);
            }
        }

        return view('subjects.index', ['subjects' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', Subject::class);

        return view('subjects.form', ['subject' => new Subject]);
    }

    public function store(SaveSubjectRequest $request, SubjectService $service): RedirectResponse
    {
        $subject = $service->save($request->user(), null, $request->validated());

        return redirect()->route('subjects.show', $subject)->with('status', 'Subject created.');
    }

    public function show(Subject $subject): View
    {
        Gate::authorize('view', $subject);

        return view('subjects.show', compact('subject'));
    }

    public function edit(Subject $subject): View
    {
        Gate::authorize('update', $subject);

        return view('subjects.form', compact('subject'));
    }

    public function update(SaveSubjectRequest $request, Subject $subject, SubjectService $service): RedirectResponse
    {
        $service->save($request->user(), $subject, $request->validated());

        return redirect()->route('subjects.show', $subject)->with('status', 'Subject saved.');
    }

    public function activate(Request $request, Subject $subject, SubjectService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $subject, SubjectStatus::Active);

        return redirect()->route('subjects.show', $subject)->with('status', 'Subject activated.');
    }

    public function deactivate(Request $request, Subject $subject, SubjectService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $subject, SubjectStatus::Inactive);

        return redirect()->route('subjects.show', $subject)->with('status', 'Subject deactivated.');
    }
}
