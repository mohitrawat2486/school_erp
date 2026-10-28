<?php

namespace App\Http\Controllers;

use App\Enums\ExamSubjectStatus;
use App\Http\Requests\ExamSubjectIndexRequest;
use App\Http\Requests\SaveExamSubjectRequest;
use App\Http\Requests\SelectExamSubjectRequest;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Services\ExamSubjectDirectory;
use App\Services\ExamSubjectService;
use App\Support\MarksConfiguration;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExamSubjectController extends Controller
{
    public function index(ExamSubjectIndexRequest $request, ExamSubjectDirectory $directory): View
    {
        return view('exam-subjects.index', ['subjects' => $directory->search($request->validated()), 'filters' => $request->validated()]);
    }

    public function create(SelectExamSubjectRequest $request, Exam $exam): View
    {
        $exam->load(['academicYear', 'schoolClass', 'examTerm']);
        $query = ClassSubject::with('subject')->where('academic_year_id', $exam->academic_year_id)->where('class_id', $exam->class_id)->where('status', 'ACTIVE')->whereHas('subject', fn ($s) => $s->where('status', 'ACTIVE'))->whereDoesntHave('examSubjects', fn ($s) => $s->where('exam_id', $exam->id));
        if ($id = $request->validated('class_subject_id')) {
            $allocation = $query->whereKey($id)->firstOrFail();

            return view('exam-subjects.form', ['record' => new ExamSubject, 'exam' => $exam, 'allocation' => $allocation, 'defaults' => $allocation->only(MarksConfiguration::FIELDS)]);
        }
        $q = $request->validated('q');
        if ($q !== null && $q !== '') {
            $query->whereHas('subject', fn ($s) => $s->where(fn ($i) => $i->where('name', 'like', '%'.$q.'%')->orWhere('code', 'like', '%'.$q.'%')));
        }

        return view('exam-subjects.select', ['exam' => $exam, 'allocations' => $query->orderBy('subject_id')->orderBy('id')->paginate(20)->withQueryString(), 'q' => $q]);
    }

    public function store(SaveExamSubjectRequest $request, Exam $exam, ExamSubjectService $service): RedirectResponse
    {
        $record = $service->save($request->user(), $exam, null, $request->validated());

        return redirect()->route('exam-subjects.show', $record)->with('status', 'Exam subject configured.');
    }

    public function show(ExamSubject $exam_subject): View
    {
        Gate::authorize('view', $exam_subject);
        $exam_subject->load(['exam.academicYear', 'exam.schoolClass', 'subject', 'classSubject']);

        return view('exam-subjects.show', ['record' => $exam_subject]);
    }

    public function edit(ExamSubject $exam_subject): View
    {
        Gate::authorize('update', $exam_subject);

        return view('exam-subjects.form', ['record' => $exam_subject, 'exam' => $exam_subject->exam, 'allocation' => $exam_subject->classSubject->load('subject'), 'defaults' => $exam_subject->only(MarksConfiguration::FIELDS)]);
    }

    public function update(SaveExamSubjectRequest $request, ExamSubject $exam_subject, ExamSubjectService $service): RedirectResponse
    {
        $service->save($request->user(), $exam_subject->exam, $exam_subject, $request->validated());

        return redirect()->route('exam-subjects.show', $exam_subject)->with('status', 'Exam marks configuration saved.');
    }

    public function activate(Request $request, ExamSubject $exam_subject, ExamSubjectService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $exam_subject, ExamSubjectStatus::Active);

        return back()->with('status', 'Exam subject activated.');
    }

    public function deactivate(Request $request, ExamSubject $exam_subject, ExamSubjectService $service): RedirectResponse
    {
        $service->setStatus($request->user(),$exam_subject,ExamSubjectStatus::Inactive);

        return back()->with('status','Exam subject deactivated; record retained.');
    }
}
