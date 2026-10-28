<?php

namespace App\Services;

use App\Enums\AcademicYearStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\PromotionBatch;
use App\Models\PromotionOutcome;
use App\Models\School;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\PromotionRules;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    public function process(User $actor, array $input): PromotionBatch
    {
        $actor = $actor->fresh();
        abort_unless($actor, 403);
        Gate::forUser($actor)->authorize('create', PromotionBatch::class);
        $data = Validator::make($input, PromotionRules::rules())->validate();
        // Normalize retries independently of HTML row ordering and integer-string input types.
        $canonical = ['mode' => $data['mode'], 'joined_at' => $data['joined_at'] ?? null];
        foreach ([...PromotionRules::SOURCE, ...PromotionRules::TARGET] as $field) {
            $canonical[$field] = isset($data[$field]) ? (int) $data[$field] : null;
        }
        $rows = [];
        foreach ($data['rows'] as $index => $row) {
            $action = $row['action'];
            $placing = in_array($action, ['PROMOTE', 'REPEAT'], true);
            if ($data['mode'] === 'EXIT' && $placing) {
                throw ValidationException::withMessages(["rows.$index.action" => 'Exit batches only support withdrawal or transfer.']);
            }
            if ($placing && empty($row['roll_number'])) {
                throw ValidationException::withMessages(["rows.$index.roll_number" => 'Enter the new roll number.']);
            }
            if (! $placing && trim($row['notes'] ?? '') === '') {
                throw ValidationException::withMessages(["rows.$index.notes" => 'Enter a reason for withdrawal or transfer.']);
            }
            $rows[] = ['source_enrollment_id' => (int) $row['source_enrollment_id'], 'action' => $action, 'roll_number' => $placing ? (int) $row['roll_number'] : null, 'notes' => trim($row['notes'] ?? '') ?: null];
        }
        usort($rows, fn ($a, $b) => $a['source_enrollment_id'] <=> $b['source_enrollment_id']);
        $canonical['rows'] = $rows;
        $hash = hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $data, $canonical, $hash, $rows) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor, 403);
            Gate::forUser($actor)->authorize('create', PromotionBatch::class);
            $existing = PromotionBatch::where('operation_key', $data['operation_key'])->first();
            if ($existing) {
                if ($existing->request_hash !== $hash || (int) $existing->actor_id !== (int) $actor->id) {
                    throw ValidationException::withMessages(['operation_key' => 'This confirmation has already been used for different selections. Start a new operation.']);
                }

return $existing;
            }
            $year = AcademicYear::whereKey($data['source_academic_year_id'])->lockForUpdate()->firstOrFail();
            if (! in_array($year->status, [AcademicYearStatus::Active, AcademicYearStatus::Closed], true)) {
                throw ValidationException::withMessages(['source_academic_year_id' => 'Choose an active or closed source year.']);
            }
            $class = SchoolClass::whereKey($data['source_class_id'])->lockForUpdate()->firstOrFail();
            $section = Section::whereKey($data['source_section_id'])->where('class_id', $class->id)->lockForUpdate()->firstOrFail();
            if ($data['mode'] === 'PLACEMENT') {
                $target = AcademicYear::whereKey($data['target_academic_year_id'])->lockForUpdate()->firstOrFail();
                if ($target->status !== AcademicYearStatus::Active || $target->start_date->lte($year->end_date)) {
                    throw ValidationException::withMessages(['target_academic_year_id' => 'Choose an active target year starting after the source year ends.']);
                }
                $targetClass = SchoolClass::active()->whereKey($data['target_class_id'])->lockForUpdate()->firstOrFail();
                Section::eligible()->whereKey($data['target_section_id'])->where('class_id', $targetClass->id)->lockForUpdate()->firstOrFail();
            }
            $batch = PromotionBatch::create([...Arr::only($canonical, [...PromotionRules::SOURCE, ...PromotionRules::TARGET, 'mode', 'joined_at']), 'actor_id' => $actor->id, 'operation_key' => $data['operation_key'], 'request_hash' => $hash]);
            foreach ($rows as $index => $row) {
                $source = StudentEnrollment::whereKey($row['source_enrollment_id'])->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('view', $source);
                foreach (['academic_year_id', 'class_id', 'section_id'] as $field) {
                    if ((int) $source->$field !== (int) $data['source_'.$field]) {
                        throw ValidationException::withMessages(["rows.$index.source_enrollment_id" => 'Every selected enrollment must belong to the source year, class and section.']);
                    }
                }
                if ($source->status !== EnrollmentStatus::Active || PromotionOutcome::where('source_enrollment_id', $source->id)->exists()) {
                    throw ValidationException::withMessages(["rows.$index.source_enrollment_id" => 'This enrollment is inactive or already processed. Refresh the selection.']);
                }
                if (StudentEnrollment::where('student_id', $source->student_id)->whereHas('academicYear', fn ($q) => $q->where('start_date', '>', $year->start_date->format('Y-m-d')))->exists()) {
                    throw ValidationException::withMessages(["rows.$index.source_enrollment_id" => 'A later enrollment already exists. Use the latest placement instead.']);
                }
                $new = null;
                if (in_array($row['action'], ['PROMOTE', 'REPEAT'], true)) {
                    $same = (int) $source->class_id === (int) $data['target_class_id'];
                    if (($row['action'] === 'REPEAT') !== $same) {
                        throw ValidationException::withMessages(["rows.$index.action" => 'Repeat must retain the class; promote must choose a different class.']);
                    }
                    try {
                        $new = app(EnrollmentService::class)->save($actor, null, ['student_id' => $source->student_id, 'academic_year_id' => $data['target_academic_year_id'], 'class_id' => $data['target_class_id'], 'section_id' => $data['target_section_id'], 'roll_number' => $row['roll_number'], 'joined_at' => $data['joined_at']]);
                    } catch (ValidationException $e) {
                        $messages = [];
                        foreach ($e->errors() as $key => $messagesForField) {
                            $messages["rows.$index.$key"] = $messagesForField;
                        }throw ValidationException::withMessages($messages);
                    }
                } else {
                    if ($year->status !== AcademicYearStatus::Active) {
                        throw ValidationException::withMessages(["rows.$index.action" => 'Withdrawal and transfer require an active source year. Closed-year records are read-only.']);
                    }

                    $before = $source->only(StudentEnrollment::AUDIT_FIELDS);
                    $source->status = $row['action'] === 'WITHDRAW' ? EnrollmentStatus::Withdrawn : EnrollmentStatus::Transferred;
                    $source->save();
                    app(AuditLogger::class)->record($actor, 'ENROLLMENT_'.$source->status->value, 'student_enrollment', $source->id, $before, $source->only(StudentEnrollment::AUDIT_FIELDS), 'promotions');
                }
                $outcome = PromotionOutcome::create(['promotion_batch_id' => $batch->id, 'student_id' => $source->student_id, 'source_enrollment_id' => $source->id, 'target_enrollment_id' => $new?->id, 'action' => $row['action'], 'notes' => $row['notes']]);
                app(AuditLogger::class)->record($actor, 'STUDENT_'.$row['action'], 'promotion_outcome', $outcome->id, [], Arr::except($outcome->getAttributes(), ['created_at', 'updated_at']), 'promotions');
            }
            app(AuditLogger::class)->record($actor,'PROMOTION_BATCH_COMPLETED','promotion_batch',$batch->id,[],['count' => count($rows), 'mode' => $batch->mode],'promotions');

            return $batch;
        });
    }
}
