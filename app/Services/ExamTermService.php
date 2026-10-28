<?php

namespace App\Services;

use App\Enums\ExamTermStatus;
use App\Models\AcademicYear;
use App\Models\ExamTerm;
use App\Models\School;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\ExamSetup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExamTermService
{
    public function lock(User $actor, ?ExamTerm $term): void
    {
        School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize($term ? 'update' : 'create', $term ?? ExamTerm::class);
    }

    public function year(int $id): AcademicYear
    {
        $year = AcademicYear::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! ExamSetup::writable($year)) {
            throw ValidationException::withMessages(['academic_year_id' => 'Closed and archived academic years are read-only.']);
        }

        return $year;
    }

    public function save(User $actor, ?ExamTerm $term, array $data): ExamTerm
    {
        Validator::make($data, ['name' => ['required', 'string', 'max:100'], 'academic_year_id' => $term ? ['prohibited'] : ['required', 'integer', 'min:1'], 'school_id' => ['prohibited'], 'status' => ['prohibited']])->validate();

        return DB::transaction(function () use ($actor, $term, $data) {
            $this->lock($actor, $term);
            $record = $term ? ExamTerm::whereKey($term->id)->lockForUpdate()->firstOrFail() : new ExamTerm;
            $creating = ! $record->exists;
            if (! $creating) {
                $this->lock($actor, $record);
            }
            $year = $this->year($creating ? (int) $data['academic_year_id'] : $record->academic_year_id);
            $query = ExamTerm::where('academic_year_id', $year->id)->where('name', $data['name']);
            if (! $creating) {
                $query->whereKeyNot($record->id);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages(['name' => 'This term name is already used in the academic year.']);
            }
            $before = $creating ? [] : $record->only(ExamTerm::AUDIT_FIELDS);
            $record->academic_year_id = $year->id;
            $record->name = $data['name'];
            if (! $creating && $record->isDirty('name') && $record->exams()->where('status', '!=', 'DRAFT')->exists()) {
                throw ValidationException::withMessages(['name' => 'Terms with released or closed exams cannot be renamed.']);
            }
            if (! $creating && ! $record->isDirty()) {
                return $record;
            }
            $record->save();
            app(AuditLogger::class)->record($actor, $creating ? 'EXAM_TERM_CREATED' : 'EXAM_TERM_UPDATED', 'exam_term', $record->id, $before, $record->only(ExamTerm::AUDIT_FIELDS), 'examinations');

            return $record;
        });
    }

    public function setStatus(User $actor, ExamTerm $term, ExamTermStatus $status): void
    {
        DB::transaction(function () use ($actor, $term, $status) {
            $this->lock($actor, $term);
            $record = ExamTerm::whereKey($term->id)->lockForUpdate()->firstOrFail();
            $this->year($record->academic_year_id);
            if ($record->status === $status) {
                return;
            }
            if ($status === ExamTermStatus::Inactive && $record->exams()->whereNotIn('status', ['DRAFT', 'CLOSED'])->exists()) {
                throw ValidationException::withMessages(['term' => 'Close or return released exams to draft before deactivating the term.']);
            }
            $before = $record->only(ExamTerm::AUDIT_FIELDS);
            $record->status = $status;
            $record->save();
            app(AuditLogger::class)->record($actor, $status === ExamTermStatus::Active ? 'EXAM_TERM_ACTIVATED' : 'EXAM_TERM_DEACTIVATED', 'exam_term', $record->id, $before, $record->only(ExamTerm::AUDIT_FIELDS), 'examinations');
        });
    }
}
