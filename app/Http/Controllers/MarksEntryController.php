<?php

namespace App\Http\Controllers;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\StudentMark;
use App\Services\MarksAccess;
use App\Services\MarksEntryService;
use Illuminate\Http\Request;

class MarksEntryController extends Controller
{
    public function select(Request $request, MarksAccess $access)
    {
        $filters = $request->validate(['academic_year_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100']);
        $subjects = $access->subjects($request->user());
        $year = null;
        if ($id = $filters['academic_year_id'] ?? null) {
            $year = AcademicYear::whereIn('id', (clone $subjects)->select('academic_year_id'))->findOrFail($id);
            $query = Exam::with('schoolClass')->where('academic_year_id', $id)->whereIn('id', $subjects->select('exam_id'));
        } else {
            $query = AcademicYear::whereIn('id', $subjects->select('academic_year_id'));
        }
        if ($q = $filters['q'] ?? null) {
            $query->where('name', 'like', '%'.$q.'%');
        }

        return view('marks.select', ['year' => $year, 'records' => $query->orderByDesc('id')->paginate(20)->withQueryString(), 'q' => $filters['q'] ?? '']);
    }

    public function index(Request $request, MarksAccess $access)
    {
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'academic_year_id' => 'nullable|integer|min:1', 'exam_id' => 'nullable|integer|min:1', 'class_id' => 'nullable|integer|min:1']);
        $query = $access->subjects($request->user());
        foreach (['academic_year_id', 'exam_id', 'class_id'] as $field) {
            if ($filters[$field] ?? null) {
                $query->where($field, $filters[$field]);
            }
        }
        if ($q = $filters['q'] ?? null) {
            $query->where(fn ($s) => $s->whereHas('subject', fn ($t) => $t->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', '%'.$q.'%'))->orWhereHas('exam', fn ($e) => $e->where('name', 'like', '%'.$q.'%')));
        }

        return view('marks.index', ['subjects' => $query->orderByDesc('exam_id')->orderBy('subject_id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function show(Request $request, ExamSubject $exam_subject, MarksAccess $access, MarksEntryService $service)
    {
        $access->authorize($request->user(), $exam_subject);
        $filters = $request->validate(['section_id' => ['nullable', 'integer', 'min:1']]);
        $sections = $access->sections($request->user(), $exam_subject)->orderBy('name')->paginate(20, ['*'], 'sections_page')->withQueryString();
        $section = null;
        $roster = null;
        $marks = collect();
        if ($id = $filters['section_id'] ?? null) {
            $section = Section::where('class_id', $exam_subject->class_id)->findOrFail($id);
            $access->authorize($request->user(), $exam_subject, $section->id);
            $roster = $service->roster($exam_subject, $section)->orderBy('roll_number')->paginate(20)->withQueryString();
            $marks = StudentMark::where('exam_subject_id', $exam_subject->id)->whereIn('enrollment_id', $roster->pluck('id'))->get()->keyBy('enrollment_id');
        }

        return view('marks.show', ['subject' => $exam_subject->load(['subject', 'exam.academicYear', 'exam.schoolClass']), 'sections' => $sections, 'section' => $section, 'roster' => $roster, 'marks' => $marks, 'writable' => $service->writable($exam_subject) && (! $section || $section->isEligible()) && $request->user()->can('marks.enter')]);
    }

    public function store(Request $request, ExamSubject $exam_subject, MarksEntryService $service)
    {
        $data = $request->validate(['section_id' => 'required|integer|min:1', 'action' => 'required|in:draft,submit', 'rows' => 'required|array|min:1|max:20']);
        $section = Section::findOrFail($data['section_id']);
        $service->save($request->user(), $exam_subject, $section, $data);

        return back()->with('status', $data['action'] === 'submit' ? 'Selected rows submitted. Submitted marks are read-only.' : 'Draft marks saved.');
    }
}
