<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Enums\StudentStatus;
use App\Http\Requests\SaveStudentRequest;
use App\Http\Requests\StudentIndexRequest;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\PromotionBatch;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Services\EnrollmentDirectory;
use App\Services\StudentProgression;
use App\Services\StudentService;
use App\Support\CurrentSchool;
use App\Support\StudentEnrollmentForm;
use App\Support\StudentGuardianForm;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentController extends Controller
{
    public function index(StudentIndexRequest $request): View
    {
        $filters = $request->validated();
        $year = app(CurrentSchool::class)->academicYear();
        $query = $this->directoryQuery($filters, $year);
        $totals = ['students' => Student::count(), 'active' => Student::where('status', 'ACTIVE')->count(), 'inactive' => Student::where('status', 'INACTIVE')->count(),
            'enrolled' => $year ? Student::whereHas('enrollments', fn ($enrollments) => $enrollments->where('academic_year_id', $year->id)->where('status', 'ACTIVE'))->count() : null];

        $classes = SchoolClass::with(['sections' => fn ($sections) => $sections->where('status', 'ACTIVE')->orderBy('name')->orderBy('id')])
            ->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();

        return view('students.index', [
            'students' => $query->paginateTable(20, ['*'], 'page', [])->withQueryString(),
            'filters' => $filters,
            'totals' => $totals,
            'classes' => $classes,
            'sections' => $classes->firstWhere('id', $filters['class_id'] ?? null)?->sections ?? collect(),
            'year' => $year,
            'progressionCatalog' => Gate::allows('create', PromotionBatch::class) ? app(StudentProgression::class)->catalog() : null,
        ]);
    }

    private function directoryQuery(array $filters, ?AcademicYear $year): Builder
    {
        $query = Student::with(['enrollments' => fn ($enrollments) => $enrollments->where('academic_year_id', $year?->id)->where('status', 'ACTIVE')->with(['schoolClass', 'section'])]);
        $term = data_get($filters, 'tables.page.search', $filters['q'] ?? '');
        if (is_string($term) && $term !== '') {
            $pattern = '%'.$term.'%';
            $query->where(function ($search) use ($pattern) {
                $search->where('admission_number', 'like', $pattern)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern])->orWhere('gender', 'like', $pattern)->orWhere('status', 'like', $pattern);
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($classId = $filters['class_id'] ?? null) {
            $query->whereHas('enrollments', fn ($enrollments) => $enrollments
                ->where('academic_year_id', $year?->id)
                ->where('class_id', $classId)
                ->when($filters['section_id'] ?? null, fn ($enrollments, $sectionId) => $enrollments->where('section_id', $sectionId))
                ->where('status', EnrollmentStatus::Active->value));
        }

        return $query->orderBy('first_name')->orderBy('last_name')->orderBy('id');
    }

    public function export(StudentIndexRequest $request): StreamedResponse
    {
        $year = app(CurrentSchool::class)->academicYear();
        $query = $this->directoryQuery($request->validated(), $year);
        $school = app(CurrentSchool::class)->get();

        return response()->streamDownload(function () use ($query, $year, $school) {
            app(CurrentSchool::class)->set($school);
            $stream = fopen('php://output', 'w');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['Admission number', 'Student', 'Class', 'Section', 'Roll number', 'Academic year', 'Status'], ',', '"', '');
                foreach ($query->lazy(200) as $student) {
                    $placement = $student->enrollments->first();
                    $values = [$student->admission_number, $student->fullName(), $placement?->schoolClass?->name ?? '', $placement?->section?->name ?? '', $placement?->roll_number ?? '', $year?->name ?? '', $student->status->value];
                    fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $value) ? "'".$value : $value, $values), ',', '"', '');
                }
            } finally {
                fclose($stream);
                app(CurrentSchool::class)->clear();
            }
        }, 'students.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Student::class);
        $student = new Student;

        return view('students.form', ['student' => $student] + (Gate::allows('create', Guardian::class) ? StudentGuardianForm::data($request, $student) + StudentEnrollmentForm::data($request, $student) : []));
    }

    public function guardianOptions(Request $request): JsonResponse
    {
        Gate::authorize('create', Student::class);
        Gate::authorize('create', Guardian::class);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $query = Guardian::where('status', 'ACTIVE');
        if (($term = $filters['q'] ?? '') !== '') {
            $query->where(fn ($search) => $search->whereRaw("CONCAT_WS(' ', first_name, last_name) LIKE ?", ['%'.$term.'%'])->orWhere('phone', 'like', '%'.$term.'%'));
        }
        $guardians = $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->limit(21)->get(['id', 'first_name', 'last_name', 'phone']);

        return response()->json(['guardians' => $guardians->take(20)->map(fn ($guardian) => ['id' => $guardian->id, 'name' => $guardian->fullName(), 'phone' => $guardian->phone])->values(), 'has_more' => $guardians->count() > 20])
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(SaveStudentRequest $request, StudentService $service): RedirectResponse
    {
        $student = $service->save($request->user(), null, $request->validated(), $request->file('photo'));

        return redirect()->route('students.show', $student)->with('status', 'Student created.');
    }

    public function show(Student $student): View
    {
        Gate::authorize('view', $student);
        $year = app(CurrentSchool::class)->academicYear();
        $placement = $year ? $student->enrollments()->where('academic_year_id', $year->id)->with(['schoolClass', 'section', 'promotionOutcome'])->first() : null;

        return view('students.show', [
            'student' => $student,
            'enrollments' => $student->enrollments()->with(EnrollmentDirectory::RELATIONS)->orderByDesc('academic_year_id')->paginateTable(10, ['*'], 'enrollment_page')->withQueryString(),
            'guardianLinks' => $student->guardianLinks()->with('guardian')->orderByDesc('is_primary')->orderBy('id')->get(),
            'currentYear' => $year,
            'currentEnrollment' => $placement?->status === EnrollmentStatus::Active ? $placement : null,
            'currentDeparture' => $placement && in_array($placement->status->value, ['WITHDRAWN', 'TRANSFERRED'], true) ? $placement->promotionOutcome : null,
        ]);
    }

    public function edit(Request $request, Student $student): View
    {
        Gate::authorize('update', $student);

        return view('students.form', compact('student') + StudentGuardianForm::data($request, $student) + StudentEnrollmentForm::data($request, $student));
    }

    public function update(SaveStudentRequest $request, Student $student, StudentService $service): RedirectResponse
    {
        $service->save($request->user(), $student, $request->validated(), $request->file('photo'));

        return redirect()->route('students.show', $student)->with('status', 'Student saved.');
    }

    public function activate(Request $request, Student $student, StudentService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $student, StudentStatus::Active);

        return redirect()->route('students.show', $student)->with('status', 'Student activated.');
    }

    public function deactivate(Request $request, Student $student, StudentService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $student, StudentStatus::Inactive);

        return redirect()->route('students.show', $student)->with('status', 'Student deactivated.');
    }

    public function photo(Student $student): StreamedResponse
    {
        Gate::authorize('view', $student);
        abort_unless($student->photo_path && Storage::disk('local')->exists($student->photo_path), 404);

        return Storage::disk('local')->response($student->photo_path, null, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
