<?php

namespace App\Http\Controllers;

use App\Enums\ClassStatus;
use App\Http\Requests\SaveSchoolClassRequest;
use App\Http\Requests\SchoolClassIndexRequest;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Services\CreateClassWithSetup;
use App\Services\SchoolClassService;
use App\Services\UpdateClassWithSetup;
use App\Support\ClassSetupState;
use App\Support\CurrentSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ViewErrorBag;
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

        if ($request->user()->can('section.view')) {
            $query->with(['sections' => fn ($q) => $q->where('status', 'ACTIVE')->orderBy('name')]);
        }

        return view('classes.index', ['classes' => $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginateTable(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', SchoolClass::class);

        return $this->form(new SchoolClass);
    }

    private function form(SchoolClass $schoolClass): View
    {
        $year = app(CurrentSchool::class)->academicYear();
        $year = $year?->status->value === 'ACTIVE' ? $year : null;
        $subjects = collect();
        $copyClasses = collect();
        $selectedSubjectIds = [];
        $lockedSubjectIds = [];
        $retainedSubjects = collect();
        $sectionDefaults = $schoolClass->exists ? [] : ['A' => ['name' => 'A']];
        $lockedSectionNames = [];
        $sectionCapacities = [];
        if ($schoolClass->exists && auth()->user()->can('create', Section::class)) {
            $existingSections = $schoolClass->sections()->withUsage()->orderBy('name')->get();
            foreach ($existingSections as $section) {
                $sectionCapacities[$section->name] = $section->capacity;
                if ($section->status->value !== 'ACTIVE') {
                    continue;
                }
                $key = in_array($section->name, ['A', 'B', 'C', 'D'], true) ? $section->name : 'existing'.$section->id;
                $sectionDefaults[$key] = ['name' => $section->name, 'capacity' => $section->capacity];
                if ($section->isInUse()) {
                    $lockedSectionNames[] = $section->name;
                }
            }
        }
        if ($year && auth()->user()->can('create', ClassSubject::class)) {
            $subjects = Subject::where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'code']);
            $copyClasses = SchoolClass::active()->when($schoolClass->exists, fn ($q) => $q->whereKeyNot($schoolClass->id))->whereHas('classSubjects', fn ($q) => $q->where('academic_year_id', $year->id)->where('status', 'ACTIVE')->whereHas('subject', fn ($s) => $s->where('status', 'ACTIVE')))
                ->with(['classSubjects' => fn ($q) => $q->where('academic_year_id', $year->id)->where('status', 'ACTIVE')->whereHas('subject', fn ($s) => $s->where('status', 'ACTIVE'))])
                ->orderBy('sort_order')->orderBy('name')->get();
            if ($schoolClass->exists) {
                $allocations = $schoolClass->classSubjects()->where('academic_year_id', $year->id)->where('status', 'ACTIVE')->with('subject')->withCount('examSubjects')->get();
                $assignedSubjects = TeacherAssignment::where('class_id', $schoolClass->id)->where('academic_year_id', $year->id)->whereNotNull('subject_id')->pluck('subject_id')->all();
                foreach ($allocations as $allocation) {
                    if ($allocation->subject->status->value !== 'ACTIVE') {
                        $retainedSubjects->push($allocation->subject);

                        continue;
                    }
                    $selectedSubjectIds[] = $allocation->subject_id;
                    if ($allocation->exam_subjects_count || in_array($allocation->subject_id, $assignedSubjects)) {
                        $lockedSubjectIds[] = $allocation->subject_id;
                    }
                }
            }
        }
        $setupEditable = ! $schoolClass->exists || $schoolClass->status === ClassStatus::Active;
        $setupRevision = $schoolClass->exists ? ClassSetupState::revision($schoolClass) : null;
        $teacherPanelData = null;
        if (auth()->user()->can('create', TeacherAssignment::class)) {
            $teacherSections = $schoolClass->exists ? $schoolClass->sections()->where('status', 'ACTIVE')->orderBy('name')->get()->map(fn ($section) => ['key' => in_array($section->name, ['A', 'B', 'C', 'D'], true) ? $section->name : 'existing'.$section->id, 'id' => $section->id, 'name' => $section->name])->values()->all() : [];
            $teacherSubjects = $schoolClass->exists && $year ? $schoolClass->classSubjects()->where('academic_year_id', $year->id)->where('status', 'ACTIVE')->whereHas('subject', fn ($q) => $q->where('status', 'ACTIVE'))->with('subject')->get()->map(fn ($allocation) => ['id' => $allocation->subject_id, 'name' => $allocation->subject->name])->values()->all() : [];
            $assignments = $schoolClass->exists && $year ? TeacherAssignment::where('class_id', $schoolClass->id)->where('academic_year_id', $year->id)->where('status', 'ACTIVE')->with('teacher.employee')->orderBy('id')->get() : collect();
            $initial = $assignments->map(fn ($assignment) => ['section_id' => $assignment->section_id, 'subject_id' => $assignment->subject_id, 'teacher_id' => $assignment->teacher_id])->values()->all();
            $teacherLabels = $assignments->pluck('teacher')->filter()->keyBy('id');
            $errors = session()->get('errors');
            $oldChanges = $errors instanceof ViewErrorBag && $errors->has('setup_revision') ? [] : old('teacher_changes', []);
            if (is_string($oldChanges)) {
                try {
                    $oldChanges = json_decode($oldChanges, true, 32, JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    $oldChanges = [];
                }
            }
            if (! is_array($oldChanges) || count($oldChanges) > 500) {
                $oldChanges = [];
            }
            $oldIds = collect($oldChanges)->pluck('teacher_id')->filter(fn ($id) => is_numeric($id))->unique();
            if ($oldIds->isNotEmpty()) {
                foreach (TeacherProfile::with('employee')->whereIn('id', $oldIds)->get() as $teacher) {
                    $teacherLabels->put($teacher->id, $teacher);
                }
            }
            $teacherPanelData = ['sections' => $teacherSections, 'subjects' => $teacherSubjects, 'assignments' => $initial, 'labels' => $teacherLabels->values()->map(fn ($teacher) => ['id' => $teacher->id, 'name' => $teacher->employee?->fullName() ?? 'Teacher unavailable', 'employee_number' => $teacher->employee?->employee_number, 'specialization' => $teacher->specialization, 'eligible' => $teacher->isEligible()])->all(), 'changes' => $oldChanges];
        }

        return view('classes.form', compact('schoolClass', 'year', 'subjects', 'copyClasses', 'selectedSubjectIds', 'lockedSubjectIds', 'retainedSubjects', 'sectionDefaults', 'lockedSectionNames', 'sectionCapacities', 'setupEditable', 'setupRevision', 'teacherPanelData'));
    }

    public function store(SaveSchoolClassRequest $request, CreateClassWithSetup $service): RedirectResponse
    {
        $schoolClass = $service->create($request->user(), $request->validated());

        return redirect()->route('classes.show', $schoolClass)->with('status', $schoolClass->name.' created with '.count($request->validated('sections', [])).' sections and '.count($request->validated('subject_ids', [])).' subjects.');
    }

    public function show(SchoolClass $school_class): View
    {
        Gate::authorize('view', $school_class);

        $year = app(CurrentSchool::class)->academicYear();
        $sections = auth()->user()->can('section.view') ? $school_class->sections()->where('status', 'ACTIVE')->orderBy('name')->get() : collect();
        $allocations = $year && auth()->user()->can('subject.view') ? $school_class->classSubjects()->with('subject')->where('academic_year_id', $year->id)->orderBy('id')->get() : collect();

        return view('classes.show', ['schoolClass' => $school_class, 'sections' => $sections, 'allocations' => $allocations, 'year' => $year]);
    }

    public function edit(SchoolClass $school_class): View
    {
        Gate::authorize('update', $school_class);

        return $this->form($school_class);
    }

    public function update(SaveSchoolClassRequest $request, SchoolClass $school_class, UpdateClassWithSetup $service): RedirectResponse
    {
        $service->update($request->user(), $school_class, $request->validated());

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
