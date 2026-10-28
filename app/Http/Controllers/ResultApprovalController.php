<?php

namespace App\Http\Controllers;

use App\Models\Exam;
use App\Models\GradingScheme;
use App\Models\ResultBatch;
use App\Models\ResultDraft;
use App\Models\StudentResult;
use App\Services\PublishedResultReader;
use App\Services\ResultApprovalService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ResultApprovalController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', ResultBatch::class);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::in(['GENERATED', 'APPROVED', 'PUBLISHED', 'RETURNED'])]]);
        $q = ResultBatch::with('exam')->withCount('drafts');
        if ($filters['q'] ?? null) {
            $q->whereHas('exam', fn ($s) => $s->where('name', 'like', '%'.$filters['q'].'%'));
        }
        if ($filters['status'] ?? null) {
            $q->where('status', $filters['status']);
        }

        return view('results.index', ['batches' => $q->orderByDesc('id')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create(Request $request)
    {
        Gate::authorize('create', ResultBatch::class);
        $data = $request->validate(['exam_id' => 'nullable|integer|min:1', 'grading_scheme_id' => 'nullable|integer|min:1', 'q' => 'nullable|string|max:100']);
        $exam = null;
        $scheme = null;
        $records = null;
        if ($id = $data['exam_id'] ?? null) {
            $exam = Exam::with(['academicYear', 'schoolClass'])->findOrFail($id);
            if ($sid = $data['grading_scheme_id'] ?? null) {
                $scheme = GradingScheme::where('status', 'ACTIVE')->findOrFail($sid);
            } else {
                $q = GradingScheme::where('status', 'ACTIVE');
                if ($data['q'] ?? null) {
                    $q->where('name', 'like', '%'.$data['q'].'%');
                }
                $records = $q->orderBy('name')->paginate(20)->withQueryString();
            }
        } else {
            $q = Exam::with(['academicYear', 'schoolClass'])->where('status', 'MARKS_ENTRY')->whereDoesntHave('resultBatches', fn ($b) => $b->where('status', '!=', 'RETURNED'));
            if ($data['q'] ?? null) {
                $q->where('name', 'like', '%'.$data['q'].'%');
            }
            $records = $q->orderByDesc('id')->paginate(20)->withQueryString();
        }

        return view('results.create', compact('exam', 'scheme', 'records', 'data'));
    }

    public function store(Request $request, ResultApprovalService $service)
    {
        $d = $request->validate(['exam_id' => 'required|integer|min:1', 'grading_scheme_id' => 'required|integer|min:1']);
        $batch = $service->generate($request->user(), Exam::findOrFail($d['exam_id']), GradingScheme::findOrFail($d['grading_scheme_id']));

        return redirect()->route('results.show', $batch)->with('status', 'Result batch generated. Marks are frozen during review.');
    }

    public function show(ResultBatch $result_batch)
    {
        Gate::authorize('view', $result_batch);
        $result_batch->load(['exam.academicYear', 'generatedBy', 'approvedBy', 'publishedBy', 'returnedBy']);

        return view('results.show', ['batch' => $result_batch, 'drafts' => $result_batch->status === 'PUBLISHED' ? app(PublishedResultReader::class)->forBatch($result_batch) : $result_batch->drafts()->orderBy('enrollment_id')->paginate(20)->withQueryString()]);
    }

    public function student(Request $request, ResultBatch $result_batch, ResultDraft $result_draft)
    {
        Gate::authorize('view', $result_batch);
        abort_unless((int) $result_draft->result_batch_id === $result_batch->id, 404);
        if ($result_batch->status === 'PUBLISHED') {
            return $this->snapshot(app(PublishedResultReader::class)->forDraft($result_batch, $result_draft->id));
        }
        $rows = $result_draft->payload['subjects'];
        $page = LengthAwarePaginator::resolveCurrentPage();
        $subjects = new LengthAwarePaginator(array_slice($rows, ($page - 1) * 20, 20), count($rows), 20, $page, ['path' => $request->url(), 'query' => $request->query()]);

        return view('results.student', ['batch' => $result_batch, 'draft' => $result_draft, 'subjects' => $subjects]);
    }

    public function snapshot(StudentResult $student_result)
    {
        Gate::authorize('view', $student_result);

        return view('results.snapshot', ['snapshot' => $student_result, 'subjects' => $student_result->subjects()->orderBy('subject_id')->paginate(20)->withQueryString()]);
    }

    public function transition(Request $request, ResultBatch $result_batch, ResultApprovalService $service)
    {
        $service->transition($request->user(), $result_batch, $request->only(['action', 'version', 'reason', 'status', 'approved_by', 'published_by']));

        return redirect()->route('results.show', $result_batch)->with('status', 'Result workflow updated.');
    }
}
