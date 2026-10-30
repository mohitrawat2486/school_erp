<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveSubjectClassesRequest;
use App\Models\AcademicYear;
use App\Models\ClassSubject;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\TeacherAssignment;
use App\Services\SyncSubjectClasses;
use App\Support\CurrentSchool;
use App\Support\SubjectClassState;
use App\Support\SubjectWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class SubjectClassesController extends Controller
{
    public function edit(Request $request, Subject $subject)
    {
        Gate::authorize('create', ClassSubject::class);
        $workspace = SubjectWorkspace::context($request);
        $data = $request->validate(['academic_year_id' => ['nullable', 'integer', Rule::exists('academic_years', 'id')->where('school_id', app(CurrentSchool::class)->id())->whereNull('deleted_at')]]);
        $years = AcademicYear::orderByDesc('start_date')->orderByDesc('id')->get();
        $current = app(CurrentSchool::class)->academicYear();
        $year = isset($data['academic_year_id']) ? $years->firstWhere('id', $data['academic_year_id']) : ($current ?? $years->first(fn ($row) => in_array($row->status->value, ['DRAFT', 'ACTIVE'], true)));
        $allocations = $year ? ClassSubject::where('academic_year_id', $year->id)->where('subject_id', $subject->id)->withCount('examSubjects')->get()->keyBy('class_id') : collect();
        $selected = $allocations->filter(fn ($row) => $row->status->value === 'ACTIVE')->keys()->all();
        $classes = SchoolClass::where(fn ($q) => $q->where('status', 'ACTIVE')->orWhereIn('id', $selected))->orderBy('sort_order')->orderBy('name')->get();
        $assigned = $year ? TeacherAssignment::where('academic_year_id', $year->id)->where('subject_id', $subject->id)->pluck('class_id')->all() : [];
        $locked = $allocations->filter(fn ($row) => $row->status->value === 'ACTIVE' && ($row->exam_subjects_count || in_array($row->class_id, $assigned) || $classes->firstWhere('id', $row->class_id)?->status->value !== 'ACTIVE'))->keys()->all();
        $editable = $year && in_array($year->status->value, ['DRAFT', 'ACTIVE'], true) && $subject->status->value === 'ACTIVE';
        $revision = $year ? SubjectClassState::revision($subject, $year) : null;

        return view('subjects.classes', compact('subject', 'workspace', 'years', 'year', 'classes', 'selected', 'locked', 'allocations', 'editable', 'revision'));
    }

    public function update(SaveSubjectClassesRequest $request, Subject $subject, SyncSubjectClasses $service)
    {
        $counts = $service->save($request->user(), $subject, $request->validated());

        return redirect()->route('class-subjects.index', ['subject_id' => $subject->id, 'academic_year_id' => $request->validated('academic_year_id'), ...SubjectWorkspace::context($request)])
            ->with('status', 'Classes updated: '.$counts['added'].' added, '.$counts['reactivated'].' restored, '.$counts['removed'].' removed.');
    }
}
