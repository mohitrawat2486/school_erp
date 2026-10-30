<?php

namespace App\Http\Controllers;

use App\Enums\StudentStatus;
use App\Http\Requests\SaveStudentRequest;
use App\Http\Requests\StudentIndexRequest;
use App\Models\Student;
use App\Services\EnrollmentDirectory;
use App\Services\StudentService;
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
        $query = Student::query();
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(function ($search) use ($pattern) {
                $search->where('admission_number', 'like', $pattern)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern]);
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return view('students.index', ['students' => $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->paginateTable(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(): View
    {
        Gate::authorize('create', Student::class);

        return view('students.form', ['student' => new Student]);
    }

    public function store(SaveStudentRequest $request, StudentService $service): RedirectResponse
    {
        $student = $service->save($request->user(), null, $request->validated(), $request->file('photo'));

        return redirect()->route('students.show', $student)->with('status', 'Student created.');
    }

    public function show(Student $student): View
    {
        Gate::authorize('view', $student);

        return view('students.show', ['student' => $student, 'enrollments' => $student->enrollments()->with(EnrollmentDirectory::RELATIONS)->orderByDesc('academic_year_id')->paginateTable(10, ['*'], 'enrollment_page')->withQueryString(), 'guardianLinks' => $student->guardianLinks()->with('guardian')->orderByDesc('is_primary')->orderBy('id')->paginateTable(20)]);
    }

    public function edit(Student $student): View
    {
        Gate::authorize('update', $student);

        return view('students.form', compact('student'));
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
