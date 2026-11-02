<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Support\StudentEnrollmentState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class StudentEnrollmentEditorController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(['student_id' => ['nullable', 'integer'], 'academic_year_id' => ['required', 'integer']]);
        $student = isset($data['student_id']) ? Student::findOrFail($data['student_id']) : null;
        Gate::authorize($student ? 'update' : 'create', $student ?? Student::class);
        Gate::authorize('create', StudentEnrollment::class);
        $year = AcademicYear::findOrFail($data['academic_year_id']);

        return response()->json(StudentEnrollmentState::data($year, $student))->header('Cache-Control', 'private, no-store');
    }
}
