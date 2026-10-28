<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\EnrollmentIndexRequest;
use App\Http\Requests\SaveEnrollmentRequest;
use App\Http\Requests\SelectEnrollmentRequest;
use App\Models\StudentEnrollment;
use App\Services\EnrollmentDirectory;
use App\Services\EnrollmentSelection;
use App\Services\EnrollmentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StudentEnrollmentController extends Controller
{
    public function index(EnrollmentIndexRequest $request, EnrollmentDirectory $directory): View
    {
        return view('enrollments.index', ['enrollments' => $directory->search($request->validated()), 'filters' => $request->validated()]);
    }

    public function create(SelectEnrollmentRequest $request, EnrollmentSelection $selection): View
    {
        $data = $selection->select($request->validated());

        return view(isset($data['step']) ? 'enrollments.select' : 'enrollments.form', $data);
    }

    public function store(SaveEnrollmentRequest $request, EnrollmentService $service): RedirectResponse
    {
        $record = $service->save($request->user(), null, $request->validated());

        return redirect()->route('enrollments.show', $record)->with('status', 'Student enrolled.');
    }

    public function show(StudentEnrollment $enrollment): View
    {
        Gate::authorize('view', $enrollment);
        $enrollment->load([...EnrollmentDirectory::RELATIONS, 'promotionOutcome.batch', 'promotionOrigin.batch']);

        return view('enrollments.show', compact('enrollment'));
    }

    public function edit(StudentEnrollment $enrollment): View
    {
        Gate::authorize('update', $enrollment);
        $enrollment->load([...EnrollmentDirectory::RELATIONS, 'promotionOutcome.batch', 'promotionOrigin.batch']);

        return view('enrollments.form', ['enrollment' => $enrollment, 'selection' => [], 'context' => ['student' => $enrollment->student, 'year' => $enrollment->academicYear, 'class' => $enrollment->schoolClass, 'section' => $enrollment->section]]);
    }

    public function update(SaveEnrollmentRequest $request, StudentEnrollment $enrollment, EnrollmentService $service): RedirectResponse
    {
        $service->save($request->user(), $enrollment, $request->validated());

        return redirect()->route('enrollments.show', $enrollment)->with('status', 'Enrollment saved.');
    }

    public function activate(Request $request, StudentEnrollment $enrollment, EnrollmentService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $enrollment, EnrollmentStatus::Active);

        return back()->with('status', 'Enrollment activated.');
    }

    public function deactivate(Request $request, StudentEnrollment $enrollment, EnrollmentService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $enrollment, EnrollmentStatus::Inactive);

        return back()->with('status', 'Enrollment deactivated; history retained.');
    }
}
