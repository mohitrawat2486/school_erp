<?php

namespace App\Http\Controllers;

use App\Http\Requests\GuardianIndexRequest;
use App\Http\Requests\SaveStudentGuardianRequest;
use App\Models\Guardian;
use App\Models\Student;
use App\Services\GuardianDirectory;
use App\Services\StudentGuardianService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class StudentGuardianController extends Controller
{
    public function index(GuardianIndexRequest $request, Student $student): View
    {
        Gate::authorize('update', $student);

        return view('guardians.select', ['student' => $student, 'guardians' => app(GuardianDirectory::class)->search($request->validated(), $student->id), 'filters' => $request->validated()]);
    }

    public function update(SaveStudentGuardianRequest $request, Student $student, Guardian $guardian, StudentGuardianService $service): RedirectResponse
    {
        $service->save($request->user(), $student, $guardian, $request->validated());

        return back()->with('status', 'Student guardian contact saved.');
    }

    public function unlink(Request $request, Student $student, Guardian $guardian, StudentGuardianService $service): RedirectResponse
    {
        $service->unlink($request->user(), $student, $guardian);

        return back()->with('status','Guardian unlinked; history retained.');
    }
}
