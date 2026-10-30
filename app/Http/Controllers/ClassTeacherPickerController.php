<?php

namespace App\Http\Controllers;

use App\Models\TeacherAssignment;
use App\Models\TeacherProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ClassTeacherPickerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        Gate::authorize('create', TeacherAssignment::class);
        abort_unless($request->user()->can('class.manage'), 403);
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|between:1,100000']);
        $query = TeacherProfile::eligible()->with('employee');
        if (($term = trim($data['q'] ?? '')) !== '') {
            $pattern = '%'.$term.'%';
            $query->where(fn ($q) => $q->where('specialization', 'like', $pattern)->orWhereHas('employee', fn ($e) => $e->where(fn ($names) => $names->whereRaw("CONCAT_WS(' ', first_name, middle_name, last_name) LIKE ?", [$pattern])->orWhere('employee_number', 'like', $pattern))));
        }
        $teachers = $query->orderBy('id')->paginate(12);

        return response()->json([
            'data' => $teachers->getCollection()->map(fn ($teacher) => ['id' => $teacher->id, 'name' => $teacher->employee->fullName(), 'employee_number' => $teacher->employee->employee_number, 'specialization' => $teacher->specialization]),
            'current_page' => $teachers->currentPage(), 'last_page' => $teachers->lastPage(), 'total' => $teachers->total(),
        ])->header('Cache-Control', 'private, no-store');
    }
}
