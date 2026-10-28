<?php

namespace App\Services;

use App\Models\ResultBatch;
use App\Models\School;
use App\Models\StudentResult;
use App\Models\StudentResultSubject;
use App\Models\User;
use App\Support\CurrentSchool;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResultSnapshotService
{
    public function archive(User $actor, ResultBatch $batch): int
    {
        abort_unless((int) $batch->school_id === app(CurrentSchool::class)->id(), 403);

        return DB::transaction(function () use ($actor, $batch) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor && $actor->can('result.view') && $actor->can('result.publish'), 403);
            $batch = ResultBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($batch->status !== 'PUBLISHED' || ! $batch->published_at || ! $batch->published_by || ! $batch->approved_at || ! $batch->approved_by) {
                throw ValidationException::withMessages(['snapshot' => 'Only fully published batches can be archived.']);
            }
            $drafts = $batch->drafts()->orderBy('enrollment_id')->get();
            $payload = $batch->calculation_context;
            $payload['results'] = $drafts->pluck('payload')->all();
            if ($drafts->isEmpty() || ! hash_equals($batch->payload_hash, ResultSourceFingerprint::digest($payload))) {
                throw ValidationException::withMessages(['snapshot' => 'The saved publication payload is incomplete or changed.']);
            }
            if ($batch->snapshots_completed_at) {
                if ($batch->studentResults()->count() !== $drafts->count()) {
                    throw ValidationException::withMessages(['snapshot' => 'Archived student counts do not match the publication.']);
                }

                return 0;
            }
            if ($batch->studentResults()->exists()) {
                throw ValidationException::withMessages(['snapshot' => 'An incomplete archive already exists. Investigate before continuing.']);
            }
            foreach ($drafts as $draft) {
                $row = $draft->payload;
                if ((int) ($row['school_id'] ?? 0) !== $batch->school_id || (int) ($row['exam_id'] ?? 0) !== $batch->exam_id || (int) ($row['student_id'] ?? 0) !== $draft->student_id || (int) ($row['enrollment_id'] ?? 0) !== $draft->enrollment_id || empty($row['subjects'])) {
                    throw ValidationException::withMessages(['snapshot' => 'Saved publication identities or subjects are invalid.']);
                }
                $record = new StudentResult;
                $fields = ['academic_year_id', 'class_id', 'exam_id', 'student_id', 'enrollment_id', 'section_id', 'grading_scheme_id', 'grading_scheme_version', 'student_name', 'section_name', 'roll_number', 'grading_scheme_name', 'total_max_marks', 'obtained_marks', 'percentage', 'grade', 'grade_point', 'grade_min_percentage', 'grade_max_percentage', 'grade_remarks', 'rank', 'result_status'];
                $data = array_intersect_key($row, array_flip($fields));
                foreach (['exam_name', 'academic_year_name', 'class_name'] as $f) {
                    $data[$f] = $batch->calculation_context[$f];
                }
                $record->forceFill($data + ['result_batch_id' => $batch->id, 'source_draft_id' => $draft->id, 'remarks' => $row['remarks'] ?? null, 'report_context' => ['admission_number' => $row['admission_number'] ?? null, 'date_of_birth' => $row['date_of_birth'] ?? null], 'published_at' => $batch->published_at, 'version' => $batch->revision, 'source_payload_hash' => ResultSourceFingerprint::digest($row)]);
                $record->save();
                foreach ($row['subjects'] as $subject) {
                    $child = new StudentResultSubject;
                    $fields = ['subject_id', 'subject_name', 'exam_subject_id', 'marks_id', 'marks_version', 'theory_marks', 'practical_marks', 'theory_max_marks', 'practical_max_marks', 'passing_marks', 'max_marks', 'obtained_marks', 'percentage', 'grade', 'grade_point', 'grade_rule_id', 'grade_min_percentage', 'grade_max_percentage', 'grade_remarks', 'is_absent', 'result_status', 'remarks'];
                    $child->forceFill(array_intersect_key($subject, array_flip($fields)) + ['student_result_id' => $record->id]);
                    $child->save();
                }
            }
            DB::table('result_batches')->where('school_id', $batch->school_id)->where('id', $batch->id)->update(['snapshots_completed_at' => now()]);
            app(AuditLogger::class)->record($actor, 'RESULT_SNAPSHOTS_ARCHIVED', 'result_batch', $batch->id, [], ['students' => $drafts->count(), 'version' => $batch->revision, 'payload_hash' => $batch->payload_hash], 'results');

            return $drafts->count();
        });
    }
}
