<?php

namespace App\Http\Controllers;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\SubjectStatus;
use App\Http\Requests\ClassSubjectIndexRequest;
use App\Http\Requests\SaveClassSubjectRequest;
use App\Http\Requests\SelectClassSubjectRequest;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Services\ClassSubjectService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ClassSubjectController extends Controller
{
    private const RELATIONS = ['academicYear', 'schoolClass', 'subject'];

    public function index(ClassSubjectIndexRequest $request): View
    {
        $filters = $request->validated();
        $query = ClassSubject::with(self::RELATIONS);
        foreach (['academic_year_id', 'class_id', 'subject_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->whereHas('subject', fn ($s) => $s->where(fn ($identity) => $identity->where('name', 'like', $p)->orWhere('code', 'like', $p)));
        }

        return view('class-subjects.index', ['allocations' => $query->orderByDesc('academic_year_id')->orderBy('class_id')->orderBy('id')->paginate(20)->withQueryString(), 'filters' => $filters, 'filterYear' => isset($filters['academic_year_id']) ? AcademicYear::findOrFail($filters['academic_year_id']) : null, 'filterClass' => isset($filters['class_id']) ? SchoolClass::findOrFail($filters['class_id']) : null, 'filterSubject' => isset($filters['subject_id']) ? Subject::findOrFail($filters['subject_id']) : null]);
    }

    private function picker(Builder $query, SelectClassSubjectRequest $request, string $step, array $selection, bool $creating): View
    {
        $q = $request->validated('q');
        if ($q !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(function ($s) use ($p, $step) {
                $s->where('name', 'like', $p);
                if ($step !== 'year') {
                    $s->orWhere('code', 'like', $p);
                }
            });
        }
        if ($step === 'class') {
            $query->orderBy('sort_order');
        } elseif ($step === 'year') {
            $query->orderByDesc('start_date');
        }

        return view('class-subjects.select', ['items' => $query->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(), 'step' => $step, 'selection' => $selection, 'creating' => $creating, 'q' => $q]);
    }

    private function selection(SelectClassSubjectRequest $request, bool $creating): View|RedirectResponse
    {
        $data = $request->validated();
        $years = AcademicYear::query();
        if ($creating) {
            $years->whereIn('status', [AcademicYearStatus::Draft->value, AcademicYearStatus::Active->value]);
        }
        if (! isset($data['academic_year_id'])) {
            return $this->picker($years, $request, 'year', [], $creating);
        }
        $year = $years->whereKey($data['academic_year_id'])->firstOrFail();
        $selection = ['academic_year_id' => $year->id];
        $classes = SchoolClass::query();
        if ($creating) {
            $classes->active();
        }
        if (! isset($data['class_id'])) {
            return $this->picker($classes, $request, 'class', $selection, $creating);
        }
        $class = $classes->whereKey($data['class_id'])->firstOrFail();
        $selection['class_id'] = $class->id;
        if (! $creating) {
            return redirect()->route('class-subjects.index', $selection);
        }
        if (! isset($data['subject_id'])) {
            $subjects = Subject::where('status', SubjectStatus::Active->value)->whereDoesntHave('classSubjects', fn ($q) => $q->where('academic_year_id', $year->id)->where('class_id', $class->id));

            return $this->picker($subjects, $request, 'subject', $selection, true);
        }
        $subject = Subject::where('status', SubjectStatus::Active->value)->whereKey($data['subject_id'])->firstOrFail();

        return view('class-subjects.form', ['allocation' => new ClassSubject, 'year' => $year, 'schoolClass' => $class, 'subject' => $subject]);
    }

    public function create(SelectClassSubjectRequest $request): View|RedirectResponse
    {
        return $this->selection($request, true);
    }

    public function chooseFilters(SelectClassSubjectRequest $request): View|RedirectResponse
    {
        return $this->selection($request, false);
    }

    public function store(SaveClassSubjectRequest $request, ClassSubjectService $service): RedirectResponse
    {
        $allocation = $service->save($request->user(), null, $request->validated());

        return redirect()->route('class-subjects.show', $allocation)->with('status', 'Class subject allocated.');
    }

    public function show(ClassSubject $class_subject): View
    {
        Gate::authorize('view', $class_subject);
        $class_subject->load(self::RELATIONS);

        return view('class-subjects.show', ['allocation' => $class_subject]);
    }

    public function edit(ClassSubject $class_subject): View
    {
        Gate::authorize('update', $class_subject);
        $class_subject->load(self::RELATIONS);
        abort_unless($class_subject->isEditable(), 403, 'Closed and archived years are read-only.');

        return view('class-subjects.form', ['allocation' => $class_subject, 'year' => $class_subject->academicYear, 'schoolClass' => $class_subject->schoolClass, 'subject' => $class_subject->subject]);
    }

    public function update(SaveClassSubjectRequest $request, ClassSubject $class_subject, ClassSubjectService $service): RedirectResponse
    {
        $service->save($request->user(), $class_subject, $request->validated());

        return redirect()->route('class-subjects.show', $class_subject)->with('status', 'Class subject saved.');
    }

    public function activate(Request $request, ClassSubject $class_subject, ClassSubjectService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $class_subject, ClassSubjectStatus::Active);

        return redirect()->route('class-subjects.show', $class_subject)->with('status', 'Class subject activated.');
    }

    public function deactivate(Request $request, ClassSubject $class_subject, ClassSubjectService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $class_subject, ClassSubjectStatus::Inactive);

        return redirect()->route('class-subjects.show', $class_subject)->with('status', 'Class subject deactivated.');
    }
}
