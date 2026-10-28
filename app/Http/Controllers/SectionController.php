<?php

namespace App\Http\Controllers;

use App\Enums\SectionStatus;
use App\Http\Requests\SaveSectionRequest;
use App\Http\Requests\SectionIndexRequest;
use App\Http\Requests\SelectSectionClassRequest;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Services\SectionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class SectionController extends Controller
{
    public function index(SectionIndexRequest $request): View
    {
        $filters = $request->validated();
        $query = Section::with('schoolClass');
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(fn ($search) => $search->where('name', 'like', $pattern)->orWhereHas('schoolClass', fn ($classes) => $classes->where(fn ($identity) => $identity->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern))));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        $selectedClass = isset($filters['class_id']) ? SchoolClass::findOrFail($filters['class_id']) : null;
        if ($selectedClass) {
            $query->where('class_id', $selectedClass->id);
        }

        return view('sections.index', ['sections' => $query->orderBy('class_id')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters, 'selectedClass' => $selectedClass]);
    }

    private function classPicker(SelectSectionClassRequest $request, bool $creating): View
    {
        $filters = $request->validated();
        $query = SchoolClass::query();
        if ($creating) {
            $query->active();
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(fn ($search) => $search->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern));
        }

        return view('sections.select-class', ['classes' => $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters, 'creating' => $creating]);
    }

    public function chooseClass(SelectSectionClassRequest $request): View
    {
        return $this->classPicker($request, false);
    }

    public function create(SelectSectionClassRequest $request): View
    {
        $filters = $request->validated();
        if (isset($filters['class_id'])) {
            return view('sections.form', ['section' => new Section, 'schoolClass' => SchoolClass::active()->whereKey($filters['class_id'])->firstOrFail()]);
        }

        return $this->classPicker($request, true);
    }

    public function store(SaveSectionRequest $request, SectionService $service): RedirectResponse
    {
        $section = $service->save($request->user(), null, $request->validated());

        return redirect()->route('sections.show', $section)->with('status', 'Section created.');
    }

    public function show(Section $section): View
    {
        Gate::authorize('view', $section);
        $section->load('schoolClass');

        return view('sections.show', compact('section'));
    }

    public function edit(Section $section): View
    {
        Gate::authorize('update', $section);

        return view('sections.form', ['section' => $section, 'schoolClass' => $section->schoolClass]);
    }

    public function update(SaveSectionRequest $request, Section $section, SectionService $service): RedirectResponse
    {
        $service->save($request->user(), $section, $request->validated());

        return redirect()->route('sections.show', $section)->with('status', 'Section saved.');
    }

    public function activate(Request $request, Section $section, SectionService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $section, SectionStatus::Active);

        return redirect()->route('sections.show', $section)->with('status', 'Section activated.');
    }

    public function deactivate(Request $request, Section $section, SectionService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $section, SectionStatus::Inactive);

        return redirect()->route('sections.show', $section)->with('status', 'Section deactivated.');
    }
}
