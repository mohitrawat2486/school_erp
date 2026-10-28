<?php

namespace App\Services;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\GradingScheme;
use App\Models\ResultBatch;
use App\Models\ResultDraft;
use App\Models\School;
use App\Models\Section;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ResultApprovalService
{
    public function generate(User $actor, Exam $exam, GradingScheme $scheme): ResultBatch
    {
        foreach ([$exam, $scheme] as $m) {
            abort_unless((int) $m->school_id === app(CurrentSchool::class)->id(), 403);
        }

        return DB::transaction(function () use ($actor, $exam, $scheme) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            Gate::forUser($actor)->authorize('create', ResultBatch::class);
            $exam = Exam::findOrFail($exam->id);
            $scheme = GradingScheme::findOrFail($scheme->id);
            if ($exam->status !== ExamStatus::MarksEntry || ResultBatch::where('exam_id', $exam->id)->where('status', '!=', 'RETURNED')->exists()) {
                throw ValidationException::withMessages(['exam' => 'Generate only from marks entry with no current result batch. Return the current batch before regenerating.']);
            }
            $calculation = app(ResultCalculationService::class)->calculateExam($actor, $exam, $scheme);
            $sections = Section::where('class_id', $exam->class_id)->pluck('name', 'id');
            foreach ($calculation['results'] as &$row) {
                $row['section_name'] = $sections[$row['section_id']];
            }
            unset($row);
            $calculation += ['exam_name' => $exam->name, 'academic_year_name' => $exam->academicYear->name, 'class_name' => $exam->schoolClass->name, 'grading_scheme_name' => $scheme->name, 'report_school' => app(ReportCardContext::class)->capture(app(CurrentSchool::class)->get())];
            $source = app(ResultSourceFingerprint::class)->hash($exam, $scheme);
            $context = $calculation;
            unset($context['results']);
            $batch = new ResultBatch;
            $batch->forceFill(['academic_year_id' => $exam->academic_year_id, 'class_id' => $exam->class_id, 'exam_id' => $exam->id, 'grading_scheme_id' => $scheme->id, 'revision' => (ResultBatch::where('exam_id', $exam->id)->max('revision') ?? 0) + 1, 'source_hash' => $source, 'payload_hash' => ResultSourceFingerprint::digest($calculation), 'calculation_context' => $context, 'generated_by' => $actor->id, 'generated_at' => now()]);
            $batch->save();
            foreach ($calculation['results'] as $row) {
                $draft = new ResultDraft;
                $draft->forceFill(['result_batch_id' => $batch->id, 'student_id' => $row['student_id'], 'enrollment_id' => $row['enrollment_id'], 'payload' => $row]);
                $draft->save();
            }
            $before = $exam->only(Exam::AUDIT_FIELDS);
            $exam->status = ExamStatus::Verification;
            $exam->save();
            app(AuditLogger::class)->record($actor, 'RESULT_GENERATED', 'result_batch', $batch->id, [], ['exam_id' => $exam->id, 'revision' => $batch->revision, 'source_hash' => $source, 'payload_hash' => $batch->payload_hash, 'students' => count($calculation['results'])], 'results');
            app(AuditLogger::class)->record($actor, 'EXAM_RESULT_REVIEW_STARTED', 'exam', $exam->id, $before, $exam->only(Exam::AUDIT_FIELDS), 'results');

            return $batch;
        });
    }

    public function transition(User $actor, ResultBatch $batch, array $data): void
    {
        abort_unless((int) $batch->school_id === app(CurrentSchool::class)->id(), 403);
        Validator::make($data, ['action' => 'required|in:approve,publish,return', 'version' => 'required|integer|min:1', 'reason' => 'required_if:action,return|nullable|string|max:1000', 'status' => 'prohibited', 'approved_by' => 'prohibited', 'published_by' => 'prohibited'])->validate();
        if ($data['action'] === 'return' && trim($data['reason'] ?? '') === '') {
            throw ValidationException::withMessages(['reason' => 'Explain why the batch needs correction.']);
        }
        DB::transaction(function () use ($actor, $batch, $data) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            $batch = ResultBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $exam = Exam::whereKey($batch->exam_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('transition', [$batch, $data['action']]);
            if ((int) $data['version'] !== $batch->lock_version) {
                throw ValidationException::withMessages(['version' => 'This batch changed. Reload before continuing.']);
            }
            $valid = match ($data['action']) {
                'approve' => $batch->status === 'GENERATED' && $exam->status === ExamStatus::Verification,'publish' => $batch->status === 'APPROVED' && $exam->status === ExamStatus::Approved,'return' => in_array($batch->status, ['GENERATED', 'APPROVED'], true) && in_array($exam->status, [ExamStatus::Verification, ExamStatus::Approved], true)
            };
            if (! $valid || $exam->academicYear->status->value !== 'ACTIVE') {
                throw ValidationException::withMessages(['workflow' => 'This transition is unavailable for the batch or academic year.']);
            }
            if ($data['action'] !== 'return') {
                $this->assertFresh($batch, $exam);
            }
            $before = $batch->only(ResultBatch::AUDIT_FIELDS);
            $examBefore = $exam->only(Exam::AUDIT_FIELDS);
            if ($data['action'] === 'approve') {
                $batch->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
                $exam->status = ExamStatus::Approved;
            }
            if ($data['action'] === 'publish') {
                $batch->forceFill(['status' => 'PUBLISHED', 'published_by' => $actor->id, 'published_at' => now()]);
                $exam->status = ExamStatus::Published;
            }
            if ($data['action'] === 'return') {
                $batch->forceFill(['status' => 'RETURNED', 'returned_by' => $actor->id, 'returned_at' => now(), 'return_reason' => trim($data['reason'])]);
                $exam->status = ExamStatus::MarksEntry;
            }
            $batch->lock_version++;
            $batch->save();
            $exam->save();
            if ($data['action'] === 'publish') {
                app(ResultSnapshotService::class)->archive($actor, $batch);
            }
            app(AuditLogger::class)->record($actor, match ($data['action']) {
                'approve' => 'RESULT_APPROVED','publish' => 'RESULT_PUBLISHED','return' => 'RESULT_RETURNED'
            }, 'result_batch', $batch->id, $before, $batch->only(ResultBatch::AUDIT_FIELDS), 'results');
            app(AuditLogger::class)->record($actor, 'EXAM_RESULT_'.strtoupper($data['action']), 'exam', $exam->id, $examBefore, $exam->only(Exam::AUDIT_FIELDS), 'results');
        });
    }

    private function assertFresh(ResultBatch $batch, Exam $exam): void
    {
        $scheme = GradingScheme::findOrFail($batch->grading_scheme_id);
        $hash = app(ResultSourceFingerprint::class)->hash($exam, $scheme);
        $payload = $batch->calculation_context;
        $rows = $batch->drafts()->orderBy('enrollment_id')->get();
        foreach ($rows as $row) {
            $data = $row->payload;
            if ((int) ($data['school_id'] ?? 0) !== $batch->school_id || (int) ($data['exam_id'] ?? 0) !== $batch->exam_id || (int) ($data['enrollment_id'] ?? 0) !== $row->enrollment_id || (int) ($data['student_id'] ?? 0) !== $row->student_id) {
                throw ValidationException::withMessages(['source' => 'Generated result identities changed. Return and regenerate the batch.']);
            }
        }
        $payload['results'] = $rows->pluck('payload')->all();
        if (! hash_equals($batch->source_hash, $hash) || ! hash_equals($batch->payload_hash, ResultSourceFingerprint::digest($payload))) {
            throw ValidationException::withMessages(['source' => 'Marks, roster, configuration or generated data changed. Return this batch and generate a new revision before approval or publication.']);
        }
    }
}
