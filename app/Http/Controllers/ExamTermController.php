<?php

namespace App\Http\Controllers;

use App\Enums\ExamTermStatus;
use App\Http\Requests\ExamIndexRequest;
use App\Http\Requests\SaveExamTermRequest;
use App\Http\Requests\SelectExamSetupRequest;
use App\Models\ExamTerm;
use App\Services\ExamDirectory;
use App\Services\ExamSetupSelection;
use App\Services\ExamTermService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExamTermController extends Controller
{
    public function index(ExamIndexRequest $request, ExamDirectory $directory): View
    {
        return view('exam-terms.index', ['terms' => $directory->search($request->validated(), true), 'filters' => $request->validated()]);
    }

    public function create(SelectExamSetupRequest $request, ExamSetupSelection $selection): View
    {
        $data = $selection->select($request->validated(), true);

        return view(isset($data['step']) ? 'exams.select' : 'exam-terms.form', $data);
    }

    public function store(SaveExamTermRequest $request, ExamTermService $service): RedirectResponse
    {
        $term = $service->save($request->user(), null, $request->validated());

        return redirect()->route('exam-terms.show', $term)->with('status', 'Exam term created.');
    }

    public function show(ExamTerm $exam_term): View
    {
        Gate::authorize('view', $exam_term);

        return view('exam-terms.show', ['term' => $exam_term, 'exams' => $exam_term->exams()->with(ExamDirectory::RELATIONS)->orderBy('start_date')->orderBy('id')->paginate(20)]);
    }

    public function edit(ExamTerm $exam_term): View
    {
        Gate::authorize('update', $exam_term);

        return view('exam-terms.form', ['record' => $exam_term, 'context' => ['year' => $exam_term->academicYear], 'selection' => []]);
    }

    public function update(SaveExamTermRequest $request, ExamTerm $exam_term, ExamTermService $service): RedirectResponse
    {
        $service->save($request->user(), $exam_term, $request->validated());

        return redirect()->route('exam-terms.show', $exam_term)->with('status', 'Exam term saved.');
    }

    public function activate(Request $request, ExamTerm $exam_term, ExamTermService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $exam_term, ExamTermStatus::Active);

        return back()->with('status', 'Exam term activated.');
    }

    public function deactivate(Request $request, ExamTerm $exam_term, ExamTermService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $exam_term, ExamTermStatus::Inactive);

        return back()->with('status','Exam term deactivated.');
    }
}
