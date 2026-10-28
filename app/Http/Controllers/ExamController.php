<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExamIndexRequest;
use App\Http\Requests\SaveExamRequest;
use App\Http\Requests\SelectExamSetupRequest;
use App\Models\Exam;
use App\Services\ExamDirectory;
use App\Services\ExamService;
use App\Services\ExamSetupSelection;
use App\Services\ExamSubjectDirectory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ExamController extends Controller
{
    public function index(ExamIndexRequest $request, ExamDirectory $directory): View
    {
        return view('exams.index', ['exams' => $directory->search($request->validated()), 'filters' => $request->validated()]);
    }

    public function create(SelectExamSetupRequest $request, ExamSetupSelection $selection): View
    {
        $data = $selection->select($request->validated(), false);

        return view(isset($data['step']) ? 'exams.select' : 'exams.form', $data);
    }

    public function store(SaveExamRequest $request, ExamService $service): RedirectResponse
    {
        $exam = $service->save($request->user(), null, $request->validated());

        return redirect()->route('exams.show', $exam)->with('status', 'Draft exam created.');
    }

    public function show(Exam $exam): View
    {
        Gate::authorize('view', $exam);
        $exam->load(ExamDirectory::RELATIONS);

        return view('exams.show', ['exam' => $exam, 'subjects' => $exam->examSubjects()->with(ExamSubjectDirectory::RELATIONS)->orderBy('subject_id')->paginate(20, ['*'], 'subjects_page')->withQueryString()]);
    }

    public function edit(Exam $exam): View
    {
        Gate::authorize('update', $exam);

        return view('exams.form', ['record' => $exam, 'context' => ['year' => $exam->academicYear, 'term' => $exam->examTerm, 'class' => $exam->schoolClass], 'selection' => []]);
    }

    public function update(SaveExamRequest $request, Exam $exam, ExamService $service): RedirectResponse
    {
        $service->save($request->user(), $exam, $request->validated());

        return redirect()->route('exams.show', $exam)->with('status', 'Draft exam saved.');
    }

    public function transition(Request $request, Exam $exam, string $action, ExamService $service): RedirectResponse
    {
        $service->transition($request->user(), $exam, $action);

        return back()->with('status', 'Exam status updated.');
    }
}
