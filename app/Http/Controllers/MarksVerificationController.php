<?php

namespace App\Http\Controllers;

use App\Enums\StudentMarkStatus;
use App\Models\StudentMark;
use App\Services\MarksVerificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MarksVerificationController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', StudentMark::class);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::enum(StudentMarkStatus::class)], 'exam_id' => 'nullable|integer|min:1', 'exam_subject_id' => 'nullable|integer|min:1', 'section_id' => 'nullable|integer|min:1']);
        $query = StudentMark::with(['student', 'exam.academicYear', 'exam.schoolClass', 'examSubject.subject', 'enrollment.section']);
        foreach (['status', 'exam_id', 'exam_subject_id'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($section = $filters['section_id'] ?? null) {
            $query->whereHas('enrollment', fn ($q) => $q->where('section_id', $section));
        }
        if ($q = $filters['q'] ?? null) {
            $query->where(fn ($s) => $s->whereHas('student', fn ($t) => $t->where('first_name', 'like', '%'.$q.'%')->orWhere('last_name', 'like', '%'.$q.'%')->orWhere('admission_number', 'like', '%'.$q.'%'))->orWhereHas('exam', fn ($t) => $t->where('name', 'like', '%'.$q.'%'))->orWhereHas('examSubject.subject', fn ($t) => $t->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', '%'.$q.'%')));
        }

        return view('marks-review.index', ['marks' => $query->orderByDesc('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function show(StudentMark $student_mark)
    {
        Gate::authorize('view', $student_mark);
        $student_mark->load(['student', 'exam.academicYear', 'exam.schoolClass', 'examSubject.subject', 'enrollment.section', 'enteredBy', 'submittedBy', 'verifiedBy', 'lockedBy']);

        return view('marks-review.show', ['mark' => $student_mark]);
    }

    public function transition(Request $request, StudentMark $student_mark, MarksVerificationService $service)
    {
        $service->transition($request->user(), $student_mark, $request->only(['action', 'version', 'reason', 'status', 'verified_by', 'locked_by', 'total_marks']));

        return redirect()->route('marks-review.show', $student_mark)->with('status', 'Marks workflow updated.');
    }
}
