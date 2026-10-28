<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\School;
use App\Models\StudentMark;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\MarksConfiguration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MarksVerificationService
{
    public function transition(User $actor, StudentMark $mark, array $data): void
    {
        abort_unless((int) $mark->school_id === app(CurrentSchool::class)->id(), 403);
        Validator::make($data, ['action' => 'required|in:verify,lock,unlock', 'version' => 'required|integer|min:1', 'reason' => ['required_if:action,unlock', 'nullable', 'string', 'max:1000'], 'status' => 'prohibited', 'verified_by' => 'prohibited', 'locked_by' => 'prohibited', 'total_marks' => 'prohibited'])->validate();
        if ($data['action'] === 'unlock' && trim($data['reason'] ?? '') === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why these locked marks need correction.']);
        }
        DB::transaction(function () use ($actor, $mark, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            $mark = StudentMark::whereKey($mark->id)->lockForUpdate()->firstOrFail();
            $exam = Exam::whereKey($mark->exam_id)->lockForUpdate()->firstOrFail();
            $mark->setRelation('exam', $exam);
            Gate::forUser($actor)->authorize('transition', [$mark, $data['action']]);
            if ((int) $data['version'] !== $mark->lock_version) {
                throw ValidationException::withMessages(['version' => 'These marks changed. Reload the record before continuing.']);
            }
            $expected = ['verify' => 'SUBMITTED', 'lock' => 'VERIFIED', 'unlock' => 'LOCKED'][$data['action']];
            if ($mark->status->value !== $expected || $exam->status->value !== 'MARKS_ENTRY' || $exam->academicYear->status->value !== 'ACTIVE') {
                throw ValidationException::withMessages(['workflow' => 'This action requires '.$expected.' marks in an active year and an exam accepting marks.']);
            }
            if ($data['action'] !== 'unlock') {
                $this->assertReady($mark);
            }
            $before = $mark->getAttributes();
            if ($data['action'] === 'verify') {
                $mark->forceFill(['status' => 'VERIFIED', 'verified_by' => $actor->id, 'verified_at' => now()]);
            }
            if ($data['action'] === 'lock') {
                $mark->forceFill(['status' => 'LOCKED', 'locked_by' => $actor->id, 'locked_at' => now()]);
            }
            if ($data['action'] === 'unlock') {
                $mark->forceFill(['status' => 'DRAFT', 'submitted_by' => null, 'submitted_at' => null, 'verified_by' => null, 'verified_at' => null, 'locked_by' => null, 'locked_at' => null]);
            }
            $mark->lock_version++;
            $mark->save();
            $after = $mark->getAttributes();
            if ($data['action'] === 'unlock') {
                $after['unlock_reason'] = trim($data['reason']);
            }
            app(AuditLogger::class)->record($actor, match ($data['action']) {
                'verify' => 'MARKS_VERIFIED','lock' => 'MARKS_LOCKED','unlock' => 'MARKS_UNLOCKED'
            }, 'student_mark', $mark->id, $before, $after, 'examinations');
        });
    }

    private function assertReady(StudentMark $mark): void
    {
        $subject = $mark->examSubject;
        $enrollment = $mark->enrollment;
        if (! app(MarksEntryService::class)->writable($subject) || ! $enrollment->section->isEligible() || $enrollment->status->value !== 'ACTIVE' || $enrollment->student->status->value !== 'ACTIVE') {
            throw ValidationException::withMessages(['context' => 'Resolve inactive exam, subject or student enrollment context before review.']);
        }
        app(ExamSubjectService::class)->assertReady($mark->exam);
        $rules = [];
        foreach (['theory', 'practical'] as $part) {
            $max = $subject->{$part.'_max_marks'};
            $rules[$part.'_marks'] = ($mark->is_absent || $max === null) ? ['prohibited'] : ['required', 'numeric', 'decimal:0,2', 'between:0,'.$max];
        }
        Validator::make($mark->only(['theory_marks', 'practical_marks']), $rules)->validate();
        $total = $mark->is_absent ? null : MarksConfiguration::total(['theory_max_marks' => $mark->theory_marks, 'practical_max_marks' => $mark->practical_marks]);
        if ($total !== $mark->total_marks || ! $mark->submitted_by || ! $mark->submitted_at || ($mark->status->value === 'VERIFIED' && (! $mark->verified_by || ! $mark->verified_at))) {
            throw ValidationException::withMessages(['marks' => 'These marks have invalid totals or workflow metadata.']);
        }
    }
}
