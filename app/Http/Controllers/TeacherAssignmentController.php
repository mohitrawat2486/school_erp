<?php

namespace App\Http\Controllers;

use App\Enums\AcademicYearStatus;
use App\Enums\AssignmentType;
use App\Enums\ClassSubjectStatus;
use App\Enums\SubjectStatus;
use App\Enums\TeacherAssignmentStatus;
use App\Http\Requests\AssignmentIndexRequest;
use App\Http\Requests\SelectAssignmentRequest;
use App\Http\Requests\StoreTeacherAssignmentRequest;
use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use App\Services\TeacherAssignmentService;
use App\Support\CurrentSchool;
use App\Support\TeacherWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TeacherAssignmentController extends Controller
{
    private const RELATIONS = ['academicYear', 'teacher.employee', 'schoolClass', 'section', 'subject'];

    public function index(AssignmentIndexRequest $request): View
    {
        $filters = $request->validated();
        $workspace = TeacherWorkspace::context($request);
        $teacher = isset($filters['teacher_id']) ? TeacherProfile::with('employee')->findOrFail($filters['teacher_id']) : null;
        $years = AcademicYear::orderByDesc('start_date')->orderByDesc('id')->get();
        $year = isset($filters['academic_year_id']) ? $years->firstWhere('id', $filters['academic_year_id']) : null;
        $list = TeacherWorkspace::listing($request);
        $query = TeacherAssignment::with(self::RELATIONS);
        foreach (['academic_year_id', 'teacher_id', 'class_id', 'status', 'assignment_type'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(function ($s) use ($p) {
                $s->whereHas('teacher.employee', fn ($e) => $e->where(fn ($identity) => $identity->where('employee_number', 'like', $p)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$p])))->orWhereHas('subject', fn ($subject) => $subject->where(fn ($identity) => $identity->where('name', 'like', $p)->orWhere('code', 'like', $p)));
            });
        }

        return view('assignments.index', ['assignments' => $query->orderByDesc('academic_year_id')->orderBy('class_id')->orderByDesc('id')->paginateTable(20)->withQueryString(), 'filters' => $filters, 'teacher' => $teacher, 'years' => $years, 'year' => $year, 'workspace' => $workspace, 'list' => $list, 'currentYear' => app(CurrentSchool::class)->academicYear()]);
    }

    private function picker(Builder $query, SelectAssignmentRequest $request, string $step, array $selection, bool $creating): View
    {
        $q = $request->validated('q');
        if ($q !== null && $q !== '') {
            $p = '%'.$q.'%';
            if ($step === 'teacher') {
                $query->whereHas('employee', fn ($e) => $e->where(fn ($s) => $s->where('employee_number', 'like', $p)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$p])));
            } else {
                $query->where(function ($s) use ($p, $step) {
                    $s->where('name', 'like', $p);
                    if (in_array($step, ['class', 'subject'], true)) {
                        $s->orWhere('code', 'like', $p);
                    }
                });
            }
        }
        if ($step === 'class') {
            $query->orderBy('sort_order')->orderBy('name');
        } elseif ($step === 'year') {
            $query->orderByDesc('start_date');
        }

        return view('assignments.select', ['items' => $query->orderBy('id')->paginateTable(20)->withQueryString(), 'step' => $step, 'selection' => $selection, 'creating' => $creating, 'q' => $q, 'selectedTeacher' => isset($selection['teacher_id']) ? TeacherProfile::with('employee')->findOrFail($selection['teacher_id']) : null]);
    }

    private function selection(SelectAssignmentRequest $request, bool $creating): View|RedirectResponse
    {
        $data = $request->validated();
        $workspace = TeacherWorkspace::context($request);
        $teacher = isset($data['teacher_id']) ? ($creating ? TeacherProfile::eligible() : TeacherProfile::query())->with('employee')->findOrFail($data['teacher_id']) : null;
        if ($creating && ! isset($data['assignment_type'])) {
            $selection = array_intersect_key($data, array_flip(['academic_year_id', 'teacher_id', 'class_id', 'section_id', 'scope', 'subject_id'])) + $workspace;
            if ($teacher) {
                $selection['teacher_id'] = $teacher->id;
            }

            return view('assignments.types', ['selection' => $selection, 'teacher' => $teacher]);
        }
        $selection = ($creating ? ['assignment_type' => $data['assignment_type']] : []) + $workspace;
        if ($teacher) {
            $selection['teacher_id'] = $teacher->id;
        }
        $years = AcademicYear::query();
        if ($creating) {
            $years->whereIn('status', [AcademicYearStatus::Draft->value, AcademicYearStatus::Active->value]);
        }
        if (! isset($data['academic_year_id'])) {
            return $this->picker($years, $request, 'year', $selection, $creating);
        }
        $year = $years->whereKey($data['academic_year_id'])->firstOrFail();
        $selection['academic_year_id'] = $year->id;
        if ($creating) {
            $teachers = TeacherProfile::eligible()->with('employee');
            if (! isset($data['teacher_id'])) {
                // Keep class setup context while the operator chooses a teacher.
                if (isset($data['class_id'])) {
                    $selection['class_id'] = SchoolClass::active()->whereKey($data['class_id'])->firstOrFail()->id;
                    if (isset($data['scope'])) {
                        $selection['scope'] = $data['scope'];
                    }
                }

                return $this->picker($teachers, $request, 'teacher', $selection, true);
            }
            $teacher = $teachers->whereKey($data['teacher_id'])->firstOrFail();
            $selection['teacher_id'] = $teacher->id;
        }
        $classes = SchoolClass::query();
        if ($creating) {
            $classes->active();
        }
        if ($creating && $data['assignment_type'] === AssignmentType::SubjectTeacher->value) {
            $classes->whereHas('classSubjects', fn ($q) => $q->where('academic_year_id', $year->id)->where('status', ClassSubjectStatus::Active->value)->whereHas('subject', fn ($s) => $s->where('status', SubjectStatus::Active->value)));
        }
        if (! isset($data['class_id'])) {
            return $this->picker($classes, $request, 'class', $selection, $creating);
        }
        $class = $classes->whereKey($data['class_id'])->firstOrFail();
        $selection['class_id'] = $class->id;
        if (! $creating) {
            return redirect()->route('assignments.index', $selection);
        }
        $section = null;
        if (! isset($data['scope']) || ($data['scope'] === 'SECTION' && ! isset($data['section_id']))) {
            return $this->picker(Section::eligible()->where('class_id', $class->id), $request, 'section', $selection, true);
        }
        $selection['scope'] = $data['scope'];
        if ($data['scope'] === 'SECTION') {
            $section = Section::eligible()->where('class_id', $class->id)->whereKey($data['section_id'])->firstOrFail();
            $selection['section_id'] = $section->id;
        }
        $subject = null;
        if ($data['assignment_type'] === AssignmentType::SubjectTeacher->value) {
            $subjects = Subject::where('status', SubjectStatus::Active->value)->whereHas('classSubjects', fn ($q) => $q->where('academic_year_id', $year->id)->where('class_id', $class->id)->where('status', ClassSubjectStatus::Active->value));
            if (! isset($data['subject_id'])) {
                return $this->picker($subjects, $request, 'subject', $selection, true);
            }
            $subject = $subjects->whereKey($data['subject_id'])->firstOrFail();
        }

        return view('assignments.form', compact('year', 'teacher', 'class', 'section', 'subject', 'selection'));
    }

    public function create(SelectAssignmentRequest $request): View|RedirectResponse
    {
        return $this->selection($request, true);
    }

    public function chooseFilters(SelectAssignmentRequest $request): View|RedirectResponse
    {
        return $this->selection($request, false);
    }

    public function store(StoreTeacherAssignmentRequest $request, TeacherAssignmentService $service): RedirectResponse
    {
        $assignment = $service->create($request->user(), $request->validated());

        return redirect()->route('assignments.show', ['teacher_assignment' => $assignment->id, ...TeacherWorkspace::context($request)])->with('status', 'Teacher assignment created.');
    }

    public function show(Request $request, TeacherAssignment $teacher_assignment): View
    {
        Gate::authorize('view', $teacher_assignment);
        $teacher_assignment->load(self::RELATIONS);

        return view('assignments.show', ['assignment' => $teacher_assignment, 'effective' => TeacherAssignment::effective()->whereKey($teacher_assignment->id)->exists(), 'workspace' => TeacherWorkspace::context($request)]);
    }

    public function activate(Request $request, TeacherAssignment $teacher_assignment, TeacherAssignmentService $service): RedirectResponse
    {
        $workspace = TeacherWorkspace::context($request);
        $service->setStatus($request->user(), $teacher_assignment, TeacherAssignmentStatus::Active);

        return redirect()->route('assignments.show', ['teacher_assignment' => $teacher_assignment->id, ...$workspace])->with('status', 'Assignment activated.');
    }

    public function deactivate(Request $request, TeacherAssignment $teacher_assignment, TeacherAssignmentService $service): RedirectResponse
    {
        $workspace = TeacherWorkspace::context($request);
        $service->setStatus($request->user(), $teacher_assignment, TeacherAssignmentStatus::Inactive);

        return redirect()->route('assignments.show', ['teacher_assignment' => $teacher_assignment->id, ...$workspace])->with('status', 'Assignment deactivated.');
    }
}
