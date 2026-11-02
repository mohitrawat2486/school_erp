<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentImportRequest;
use App\Models\AcademicYear;
use App\Models\Student;
use App\Services\StudentImportService;
use App\Support\CurrentSchool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StudentImportController extends Controller
{
    public function index(Request $request, StudentImportService $service): View
    {
        $service->authorize($request->user());

        return view('students.import', ['importConfig' => [
            'fields' => config('student-import.fields'), 'maxRows' => config('student-import.max_rows'), 'maxColumns' => config('student-import.max_columns'), 'maxBytes' => config('student-import.max_bytes'),
            'genders' => Student::GENDERS, 'bloodGroups' => Student::BLOOD_GROUPS,
            'years' => AcademicYear::where('status', 'ACTIVE')->orderByDesc('start_date')->get(['id', 'name']),
            'currentYear' => app(CurrentSchool::class)->academicYear()?->id,
            'admissionDate' => today()->format('Y-m-d'), 'canPlace' => $request->user()->can('student.update'),
            'validateUrl' => route('students.import.validate'), 'importUrl' => route('students.import.store'),
        ]]);
    }

    public function validate(StudentImportRequest $request, StudentImportService $service): JsonResponse
    {
        return response()->json($service->review($request->user(), $request->validated()))->header('Cache-Control', 'private, no-store');
    }

    public function store(StudentImportRequest $request, StudentImportService $service): JsonResponse
    {
        $data = $request->validated();

        return response()->json($service->import($request->user(), $data['review_token'], $data['chunk'], $data['include_warnings']))->header('Cache-Control', 'private, no-store');
    }
}
