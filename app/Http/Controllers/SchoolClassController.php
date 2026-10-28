<?php

namespace App\Http\Controllers;

use App\Enums\ClassStatus;
use App\Http\Requests\SaveSchoolClassRequest;
use App\Http\Requests\SchoolClassIndexRequest;
use App\Models\SchoolClass;
use App\Services\SchoolClassService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SchoolClassController extends Controller
{
    public function index(SchoolClassIndexRequest $request): View
    {
        $filters = $request->validated();
        $query = SchoolClass::query();
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(fn ($search) => $search->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return view('classes.index', ['classes' => $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', SchoolClass::class);

        return view('classes.form', ['schoolClass' => new SchoolClass]);
    }

    public function store(SaveSchoolClassRequest $request, SchoolClassService $service): RedirectResponse
    {
        $schoolClass = $service->save($request->user(), null, $request->validated());

        return redirect()->route('classes.show', $schoolClass)->with('status', 'Class created.');
    }

    public function show(SchoolClass $school_class): View
    {
        Gate::authorize('view', $school_class);

        return view('classes.show', ['schoolClass' => $school_class]);
    }

    public function edit(SchoolClass $school_class): View
    {
        Gate::authorize('update', $school_class);

        return view('classes.form', ['schoolClass' => $school_class]);
    }

    public function update(SaveSchoolClassRequest $request, SchoolClass $school_class, SchoolClassService $service): RedirectResponse
    {
        $service->save($request->user(), $school_class, $request->validated());

        return redirect()->route('classes.show', $school_class)->with('status', 'Class saved.');
    }

    public function activate(Request $request, SchoolClass $school_class, SchoolClassService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $school_class, ClassStatus::Active);

        return redirect()->route('classes.show', $school_class)->with('status', 'Class activated.');
    }

    public function deactivate(Request $request, SchoolClass $school_class, SchoolClassService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $school_class, ClassStatus::Inactive);

        return redirect()->route('classes.show', $school_class)->with('status', 'Class deactivated.');
    }
}
