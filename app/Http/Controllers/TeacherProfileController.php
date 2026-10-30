<?php

namespace App\Http\Controllers;

use App\Enums\EmployeeStatus;
use App\Enums\TeacherProfileStatus;
use App\Http\Requests\SaveTeacherProfileRequest;
use App\Http\Requests\SelectTeacherEmployeeRequest;
use App\Http\Requests\TeacherProfileIndexRequest;
use App\Models\Employee;
use App\Models\TeacherProfile;
use App\Services\TeacherProfileService;
use App\Support\CurrentSchool;
use App\Support\TeacherWorkspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class TeacherProfileController extends Controller
{
    public function index(TeacherProfileIndexRequest $request): View
    {
        $filters = $request->validated();
        $query = TeacherProfile::with('employee');
        $year = app(CurrentSchool::class)->academicYear();
        if ($year?->status->value !== 'ACTIVE') {
            $year = null;
        }
        $canViewAssignments = $request->user()->can('teacher_assignment.view');
        if ($year && $canViewAssignments) {
            $query->withCount(['teacherAssignments as current_assignments_count' => fn ($q) => $q->where('academic_year_id', $year->id)->where('status', 'ACTIVE')]);
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(function ($search) use ($pattern) {
                $search->whereHas('employee', fn ($employee) => $employee->where(function ($identity) use ($pattern) {
                    $identity->where('employee_number', 'like', $pattern)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern]);
                }))->orWhere('specialization', 'like', $pattern);
            });
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        return view('teachers.index', ['profiles' => $query->orderByDesc('id')->paginateTable(20)->withQueryString(), 'filters' => $filters, 'year' => $year, 'canViewAssignments' => $canViewAssignments, 'directory' => TeacherWorkspace::directory($request)]);
    }

    public function create(SelectTeacherEmployeeRequest $request): View
    {
        $workspace = TeacherWorkspace::context($request);
        $filters = $request->validated();
        $eligible = Employee::where('status', EmployeeStatus::Active->value)->whereDoesntHave('teacherProfile');
        if (isset($filters['employee_id'])) {
            return view('teachers.form', ['profile' => new TeacherProfile, 'employee' => $eligible->whereKey($filters['employee_id'])->firstOrFail(), 'workspace' => $workspace]);
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $eligible->where(fn ($search) => $search->where('employee_number', 'like', $pattern)->orWhereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern]));
        }

        return view('teachers.select-employee', ['employees' => $eligible->orderBy('first_name')->orderBy('id')->paginateTable(20)->withQueryString(), 'filters' => $filters, 'workspace' => $workspace]);
    }

    public function store(SaveTeacherProfileRequest $request, TeacherProfileService $service): RedirectResponse
    {
        $profile = $service->save($request->user(), null, $request->validated());

        return redirect()->route('teachers.show', ['teacher_profile' => $profile->id, ...TeacherWorkspace::context($request)])->with('status', 'Teacher profile created.');
    }

    public function show(Request $request, TeacherProfile $teacher_profile): View
    {
        Gate::authorize('view', $teacher_profile);
        $teacher_profile->load('employee');

        $year = app(CurrentSchool::class)->academicYear();
        if ($year?->status->value !== 'ACTIVE') {
            $year = null;
        }

        return view('teachers.show', ['profile' => $teacher_profile, 'year' => $year, 'workspace' => TeacherWorkspace::context($request)]);
    }

    public function edit(Request $request, TeacherProfile $teacher_profile): View
    {
        Gate::authorize('update', $teacher_profile);

        return view('teachers.form', ['profile' => $teacher_profile, 'employee' => $teacher_profile->employee, 'workspace' => TeacherWorkspace::context($request)]);
    }

    public function update(SaveTeacherProfileRequest $request, TeacherProfile $teacher_profile, TeacherProfileService $service): RedirectResponse
    {
        $service->save($request->user(), $teacher_profile, $request->validated());

        return redirect()->route('teachers.show', ['teacher_profile' => $teacher_profile->id, ...TeacherWorkspace::context($request)])->with('status', 'Teacher profile saved.');
    }

    public function activate(Request $request, TeacherProfile $teacher_profile, TeacherProfileService $service): RedirectResponse
    {
        $workspace = TeacherWorkspace::context($request);
        $service->setStatus($request->user(), $teacher_profile, TeacherProfileStatus::Active);

        return redirect()->route('teachers.show', ['teacher_profile' => $teacher_profile->id, ...$workspace])->with('status', 'Teacher profile activated.');
    }

    public function deactivate(Request $request, TeacherProfile $teacher_profile, TeacherProfileService $service): RedirectResponse
    {
        $workspace = TeacherWorkspace::context($request);
        $service->setStatus($request->user(), $teacher_profile, TeacherProfileStatus::Inactive);

        return redirect()->route('teachers.show', ['teacher_profile' => $teacher_profile->id, ...$workspace])->with('status', 'Teacher profile deactivated.');
    }
}
