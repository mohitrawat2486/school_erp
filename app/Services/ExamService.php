<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\ClassStatus;
use App\Enums\ExamStatus;
use App\Enums\ExamTermStatus;
use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\ExamTerm;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExamService
{
    private function authorize(User $actor, ?Exam $exam, string $ability = 'update'): void
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($exam ? $ability : 'create', $exam ?? Exam::class);
    }

    private function context(array $ids, bool $active): array
    {
        $year = app(ExamTermService::class)->year((int) $ids['academic_year_id']);
        $term = ExamTerm::whereKey($ids['exam_term_id'])->lockForUpdate()->firstOrFail();
        $class = SchoolClass::whereKey($ids['class_id'])->lockForUpdate()->firstOrFail();
        if ((int) $term->academic_year_id !== $year->id) {
            throw ValidationException::withMessages(['exam_term_id' => 'Choose a term from the selected academic year.']);
        }
        if ($active && ($term->status !== ExamTermStatus::Active || $class->status !== ClassStatus::Active)) {
            throw ValidationException::withMessages(['context' => 'An active term and class are required.']);
        }

        return [$year, $term, $class];
    }

    private function dates(Exam $exam, AcademicYear $year): void
    {
        Validator::make(['start_date' => $exam->start_date->format('Y-m-d'), 'end_date' => $exam->end_date->format('Y-m-d')], ['start_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$year->start_date->format('Y-m-d')], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date', 'before_or_equal:'.$year->end_date->format('Y-m-d')]])->validate();
    }

    public function save(User $actor, ?Exam $exam, array $data): Exam
    {
        $this->authorize($actor, $exam);
        Validator::make($data, ['name' => ['required', 'string', 'max:100'], 'start_date' => ['required', 'date_format:Y-m-d'], 'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'], 'school_id' => ['prohibited'], 'status' => ['prohibited'], ...array_fill_keys(Exam::CONTEXT, $exam ? ['prohibited'] : ['required', 'integer', 'min:1'])])->validate();

        return DB::transaction(function () use ($actor, $exam, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $record = $exam ? Exam::whereKey($exam->id)->lockForUpdate()->firstOrFail() : new Exam;
            $creating = ! $record->exists;
            $this->authorize($actor, $creating ? null : $record);
            if (! $creating && $record->status !== ExamStatus::Draft) {
                throw ValidationException::withMessages(['exam' => 'Only draft exams can be edited.']);
            }
            if ($creating) {
                foreach (Exam::CONTEXT as $field) {
                    $record->$field = $data[$field];
                }
            }
            [$year] = $this->context($record->only(Exam::CONTEXT), $creating);
            $query = Exam::where($record->only(Exam::CONTEXT))->where('name', $data['name']);
            if (! $creating) {
                $query->whereKeyNot($record->id);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages(['name' => 'This exam name is already used for the selected term and class.']);
            }
            $before = $creating ? [] : $record->only(Exam::AUDIT_FIELDS);
            $record->fill(Arr::only($data, ['name', 'start_date', 'end_date']));
            $this->dates($record, $year);
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'EXAM_CREATED' : 'EXAM_UPDATED', 'exam', $record->id, $before, $record->only(Exam::AUDIT_FIELDS), 'examinations');

            return $record;
        });
    }

    public function transition(User $actor, Exam $exam, string $action): void
    {
        $this->authorize($actor, $exam, 'manage');
        DB::transaction(function () use ($actor, $exam, $action) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $record = Exam::whereKey($exam->id)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $record, 'manage');
            [$year] = $this->context($record->only(Exam::CONTEXT), $action === 'open');
            $next = match ($action) {
                'open' => ExamStatus::Open,'draft' => ExamStatus::Draft,'close' => ExamStatus::Closed,default => throw ValidationException::withMessages(['action' => 'This workflow transition is not available.'])
            };
            $allowed = match ($action) {
                'open' => $record->status === ExamStatus::Draft && $year->status === AcademicYearStatus::Active,'draft' => $record->status === ExamStatus::Open,'close' => in_array($record->status, [ExamStatus::Draft, ExamStatus::Open], true) || ($record->status === ExamStatus::Published && $record->resultBatches()->where('status', 'PUBLISHED')->exists())
            };
            if (! $allowed) {
                throw ValidationException::withMessages(['action' => 'This action is not allowed for the exam or academic year status.']);
            }
            if ($action === 'open') {
                $this->dates($record, $year);
                app(ExamSubjectService::class)->assertReady($record);
            }
            $before = $record->only(Exam::AUDIT_FIELDS);
            $record->status = $next;
            $record->save();
            app(AuditLogger::class)->record($actor, 'EXAM_'.strtoupper($action), 'exam', $record->id, $before, $record->only(Exam::AUDIT_FIELDS), 'examinations');
        });
    }
}
