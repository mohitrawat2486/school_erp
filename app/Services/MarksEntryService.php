<?php

namespace App\Services;

use App\Enums\ExamStatus;
use App\Enums\StudentMarkStatus;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\School;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\StudentMark;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\MarksConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MarksEntryService
{
    private function hundredths(mixed $value): int
    {
        $parts = explode('.', (string) $value);

        return (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
    }

    public function writable(ExamSubject $subject): bool
    {
        return in_array($subject->exam->status, [ExamStatus::Open, ExamStatus::MarksEntry], true) && $subject->exam->academicYear->status->value === 'ACTIVE' && $subject->status->value === 'ACTIVE' && $subject->exam->schoolClass->status->value === 'ACTIVE' && $subject->exam->examTerm->status->value === 'ACTIVE' && $subject->classSubject->status->value === 'ACTIVE' && $subject->subject->status->value === 'ACTIVE';
    }

    public function roster(ExamSubject $subject, Section $section)
    {
        return StudentEnrollment::with('student')->where('academic_year_id', $subject->academic_year_id)->where('class_id', $subject->class_id)->where('section_id', $section->id)->where('status', 'ACTIVE')->whereHas('student', fn ($q) => $q->where('status', 'ACTIVE'));
    }

    public function save(User $actor, ExamSubject $subject, Section $section, array $data): void
    {
        abort_unless((int) $subject->school_id === app(CurrentSchool::class)->id() && (int) $section->school_id === app(CurrentSchool::class)->id(), 403);
        Validator::make($data, ['action' => 'required|in:draft,submit', 'rows' => 'required|array|min:1|max:20', 'rows.*' => 'array:enrollment_id,version,theory_marks,practical_marks,is_absent,remarks', 'rows.*.enrollment_id' => 'required|integer|distinct|min:1', 'rows.*.version' => 'required|integer|min:0', 'rows.*.is_absent' => 'required|boolean', 'rows.*.remarks' => 'nullable|string|max:500', 'rows.*.theory_marks' => 'nullable|numeric|decimal:0,2|between:0,9999.99', 'rows.*.practical_marks' => 'nullable|numeric|decimal:0,2|between:0,9999.99'])->validate();
        DB::transaction(function () use ($actor, $subject, $section, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            $exam = Exam::whereKey($subject->exam_id)->lockForUpdate()->firstOrFail();
            $subject = ExamSubject::whereKey($subject->id)->lockForUpdate()->firstOrFail();
            $subject->setRelation('exam', $exam);
            $section = Section::whereKey($section->id)->lockForUpdate()->firstOrFail();
            app(MarksAccess::class)->authorize($actor, $subject, $section->id);
            abort_unless($actor->can('marks.enter') && ($data['action'] !== 'submit' || $actor->can('marks.submit')), 403);
            if (! $this->writable($subject) || ! $section->isEligible() || (int) $section->class_id !== (int) $subject->class_id || $exam->examTerm->status->value !== 'ACTIVE') {
                throw ValidationException::withMessages(['context' => 'Marks entry requires an open exam, active year, term, class, section and subject.']);
            }
            app(ExamSubjectService::class)->assertReady($exam);
            foreach ($data['rows'] as $index => $row) {
                $enrollment = $this->roster($subject, $section)->whereKey($row['enrollment_id'])->lockForUpdate()->first();
                if (! $enrollment) {
                    throw ValidationException::withMessages(["rows.$index.enrollment_id" => 'This student is not in the active roster for this exam and section.']);
                }
                $mark = StudentMark::where('exam_id', $exam->id)->where('student_id', $enrollment->student_id)->where('subject_id', $subject->subject_id)->lockForUpdate()->first();
                if ($mark && ($mark->status !== StudentMarkStatus::Draft || (int) $mark->enrollment_id !== $enrollment->id)) {
                    throw ValidationException::withMessages(["rows.$index" => 'Submitted marks are read-only.']);
                }
                if ((int) $row['version'] !== ($mark?->lock_version ?? 0)) {
                    throw ValidationException::withMessages(["rows.$index" => 'Marks changed since this page was loaded. Reload before saving.']);
                }
                if ($mark) {
                    abort_unless($actor->can('marks.update'), 403);
                }
                $absent = (bool) $row['is_absent'];
                $values = [];
                $complete = true;
                foreach (['theory', 'practical'] as $component) {
                    $field = $component.'_marks';
                    $max = $subject->{$component.'_max_marks'};
                    $value = $row[$field] ?? null;
                    if ($value !== null && ($absent || $max === null || $this->hundredths($value) > $this->hundredths($max))) {
                        throw ValidationException::withMessages(["rows.$index.$field" => 'Enter a value within the configured component maximum, or leave blank when absent/unused.']);
                    }
                    if (! $absent && $max !== null && $value === null) {
                        $complete = false;
                    }
                    $values[$field] = $value;
                }
                if ($data['action'] === 'submit' && ! $complete) {
                    throw ValidationException::withMessages(["rows.$index" => 'Complete every configured component or mark the student absent before submitting.']);
                }
                $values['total_marks'] = (! $absent && $complete) ? MarksConfiguration::total(['theory_max_marks' => $values['theory_marks'], 'practical_max_marks' => $values['practical_marks']]) : null;
                $values['is_absent'] = $absent;
                $values['remarks'] = $row['remarks'] ?? null;
                $new = ! $mark;
                $before = $mark?->getAttributes() ?? [];
                $mark ??= new StudentMark;
                if ($new) {
                    $mark->forceFill(['academic_year_id' => $subject->academic_year_id, 'class_id' => $subject->class_id, 'exam_id' => $exam->id, 'exam_subject_id' => $subject->id, 'subject_id' => $subject->subject_id, 'student_id' => $enrollment->student_id, 'enrollment_id' => $enrollment->id, 'status' => 'DRAFT']);
                }
                $mark->forceFill($values);
                if ($data['action'] === 'submit') {
                    $mark->forceFill(['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
                }
                if (! $new && ! $mark->isDirty()) {
                    continue;
                }
                $mark->entered_by = $actor->id;
                $mark->lock_version = ($new ? 0 : $mark->lock_version) + 1;
                $mark->save();
                app(AuditLogger::class)->record($actor, $data['action'] === 'submit' ? 'MARKS_SUBMITTED' : 'MARKS_DRAFT_SAVED', 'student_mark', $mark->id, $before, $mark->getAttributes(), 'examinations');
            }
            if ($exam->status === ExamStatus::Open) {
                $before = $exam->only(Exam::AUDIT_FIELDS);
                $exam->status = ExamStatus::MarksEntry;
                $exam->save();
                app(AuditLogger::class)->record($actor, 'EXAM_MARKS_ENTRY_STARTED', 'exam', $exam->id, $before, $exam->only(Exam::AUDIT_FIELDS), 'examinations');
            }
        });
    }
}
