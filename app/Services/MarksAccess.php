<?php

namespace App\Services;

use App\Models\ExamSubject;
use App\Models\Section;
use App\Models\TeacherAssignment;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class MarksAccess
{
    public function elevated(User $actor): bool
    {
        return $actor->can('marks.verify') || $actor->can('marks.lock');
    }

    public function assignments(User $actor): Builder
    {
        return TeacherAssignment::effective()->where('assignment_type', 'SUBJECT_TEACHER')->whereHas('teacher.employee', fn ($q) => $q->whereKey($actor->employee_id ?? 0));
    }

    public function subjects(User $actor): Builder
    {
        abort_unless($actor->can('marks.view'), 403);
        $query = ExamSubject::with(['exam.academicYear', 'exam.schoolClass', 'subject']);
        if (! $this->elevated($actor)) {
            $query->whereExists($this->assignments($actor)->selectRaw('1')
                ->whereColumn('teacher_assignments.academic_year_id', 'exam_subjects.academic_year_id')
                ->whereColumn('teacher_assignments.class_id', 'exam_subjects.class_id')
                ->whereColumn('teacher_assignments.class_subject_id', 'exam_subjects.class_subject_id'));
        }

        return $query;
    }

    public function authorize(User $actor, ExamSubject $subject, ?int $sectionId = null): void
    {
        abort_unless($actor->can('marks.view') && (int) $actor->school_id === (int) $subject->school_id, 403);
        if ($this->elevated($actor)) {
            return;
        }
        $query = $this->assignments($actor)->where('academic_year_id', $subject->academic_year_id)->where('class_id', $subject->class_id)->where('class_subject_id', $subject->class_subject_id);
        if ($sectionId !== null) {
            $query->where(fn ($q) => $q->whereNull('section_id')->orWhere('section_id', $sectionId));
        }
        abort_unless($query->exists(), 403);
    }

    public function sections(User $actor, ExamSubject $subject): Builder
    {
        $this->authorize($actor, $subject);
        $q = Section::where('class_id', $subject->class_id);
        if (! $this->elevated($actor)) {
            $q->whereExists($this->assignments($actor)->selectRaw('1')
                ->where('academic_year_id', $subject->academic_year_id)->where('class_subject_id', $subject->class_subject_id)
                ->where(fn ($a) => $a->whereNull('teacher_assignments.section_id')->orWhereColumn('teacher_assignments.section_id', 'sections.id')));
        }

        return $q;
    }
}
