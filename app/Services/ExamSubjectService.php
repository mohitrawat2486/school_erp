<?php

namespace App\Services;

use App\Enums\ClassStatus;
use App\Enums\ClassSubjectStatus;
use App\Enums\ExamSubjectStatus;
use App\Enums\ExamTermStatus;
use App\Enums\SubjectStatus;
use App\Models\ClassSubject;
use App\Models\Exam;
use App\Models\ExamSubject;
use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\ExamMarksConfiguration;
use App\Support\MarksConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExamSubjectService
{
    private function lock(User $actor, Exam $exam): Exam
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize('update', $exam);
        $exam = Exam::whereKey($exam->id)->lockForUpdate()->firstOrFail();
        Gate::forUser($actor)->authorize('update', $exam);
        app(ExamTermService::class)->year($exam->academic_year_id);

        return $exam;
    }

    private function curriculum(Exam $exam, int $id, bool $active): ClassSubject
    {
        $allocation = ClassSubject::with('subject')->whereKey($id)->lockForUpdate()->firstOrFail();
        if ((int) $allocation->academic_year_id !== (int) $exam->academic_year_id || (int) $allocation->class_id !== (int) $exam->class_id) {
            throw ValidationException::withMessages(['class_subject_id' => 'Choose a curriculum allocation from this exam year and class.']);
        }
        if ($active && ($allocation->status !== ClassSubjectStatus::Active || $allocation->subject->status !== SubjectStatus::Active || $exam->schoolClass->status !== ClassStatus::Active || $exam->examTerm->status !== ExamTermStatus::Active)) {
            throw ValidationException::withMessages(['context' => 'An active allocation, subject, class and term are required.']);
        }

        return $allocation;
    }

    public function save(User $actor, Exam $exam, ?ExamSubject $examSubject, array $data): ExamSubject
    {
        if ($examSubject) {
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            Gate::forUser($actor)->authorize('update', $examSubject);
        }

        return DB::transaction(function () use ($actor, $exam, $examSubject, $data) {
            $exam = $this->lock($actor, $exam);
            $record = $examSubject ? ExamSubject::whereKey($examSubject->id)->lockForUpdate()->firstOrFail() : new ExamSubject;
            $creating = ! $record->exists;
            if (! $creating && (int) $record->exam_id !== (int) $exam->id) {
                throw ValidationException::withMessages(['exam_id' => 'This subject belongs to another exam.']);
            }
            foreach (['school_id', 'id', 'status', 'academic_year_id', 'class_id', 'subject_id', 'exam_id'] as $field) {
                if (array_key_exists($field, $data)) {
                    throw ValidationException::withMessages([$field => 'This field cannot be set directly.']);
                }
            }
            if (! $creating && array_key_exists('class_subject_id', $data)) {
                throw ValidationException::withMessages(['class_subject_id' => 'An existing exam subject cannot be moved.']);
            }
            if ($creating) {
                Validator::make($data, ['class_subject_id' => ['required', 'integer', 'min:1']])->validate();
            }
            $allocation = $this->curriculum($exam, $creating ? (int) $data['class_subject_id'] : $record->class_subject_id, $creating);
            if ($creating && ExamSubject::where('exam_id', $exam->id)->where('class_subject_id', $allocation->id)->exists()) {
                throw ValidationException::withMessages(['class_subject_id' => 'This subject is already configured for this exam. Reactivate its existing record if needed.']);
            }
            $before = $creating ? [] : $record->only(ExamSubject::AUDIT_FIELDS);
            if ($creating) {
                $record->exam_id = $exam->id;
                $record->class_subject_id = $allocation->id;
                foreach (['academic_year_id', 'class_id', 'subject_id'] as $field) {
                    $record->$field = $allocation->$field;
                }
            }
            // Curriculum values are copied only at creation, never dynamically inherited afterwards.
            $base = $creating ? $allocation->only(MarksConfiguration::FIELDS) : $record->only(MarksConfiguration::FIELDS);
            $record->fill(ExamMarksConfiguration::validate($data, $base));
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'EXAM_SUBJECT_CREATED' : 'EXAM_SUBJECT_UPDATED', 'exam_subject', $record->id, $before, $record->only(ExamSubject::AUDIT_FIELDS), 'examinations');

            return $record;
        });
    }

    public function setStatus(User $actor, ExamSubject $examSubject, ExamSubjectStatus $status): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize('update', $examSubject);
        DB::transaction(function () use ($actor, $examSubject, $status) {
            $exam = $this->lock($actor, $examSubject->exam);
            $record = ExamSubject::whereKey($examSubject->id)->lockForUpdate()->firstOrFail();
            $this->curriculum($exam, $record->class_subject_id, $status === ExamSubjectStatus::Active);
            if ($record->status === $status) {
                return;
            }
            if ($status === ExamSubjectStatus::Active) {
                ExamMarksConfiguration::validate($record->only(MarksConfiguration::FIELDS));
            }
            $before = $record->only(ExamSubject::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === ExamSubjectStatus::Active ? 'EXAM_SUBJECT_ACTIVATED' : 'EXAM_SUBJECT_DEACTIVATED', 'exam_subject', $record->id, $before, $record->only(ExamSubject::AUDIT_FIELDS), 'examinations');
        });
    }

    public function assertReady(Exam $exam): void
    {
        $subjects = $exam->examSubjects()->where('status', 'ACTIVE')->with('classSubject.subject')->lockForUpdate()->get();
        if ($subjects->isEmpty()) {
            throw ValidationException::withMessages(['subjects' => 'Configure at least one active exam subject before opening the exam.']);
        }
        foreach ($subjects as $subject) {
            if ($subject->classSubject->status !== ClassSubjectStatus::Active || $subject->classSubject->subject->status !== SubjectStatus::Active) {
                throw ValidationException::withMessages(['subjects' => 'Every active exam subject must have an active curriculum allocation and subject.']);
            }
            ExamMarksConfiguration::validate($subject->only(MarksConfiguration::FIELDS));
        }
    }
}
