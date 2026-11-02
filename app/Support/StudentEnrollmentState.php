<?php

namespace App\Support;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\StudentEnrollment;
use App\Services\StudentService;
use Illuminate\Support\Facades\DB;

final class StudentEnrollmentState
{
    public static function isUsed(StudentEnrollment $enrollment): bool
    {
        foreach (['student_marks', 'result_drafts', 'student_results'] as $table) {
            if (DB::table($table)->where('school_id', $enrollment->school_id)->where('enrollment_id', $enrollment->id)->exists()) {
                return true;
            }
        }

        return DB::table('promotion_outcomes')->where('school_id', $enrollment->school_id)->where(fn ($query) => $query->where('source_enrollment_id', $enrollment->id)->orWhere('target_enrollment_id', $enrollment->id))->exists();
    }

    public static function revision(AcademicYear $year, ?Student $student, ?StudentEnrollment $enrollment): string
    {
        return hash('sha256', json_encode([
            $year->only(['id', 'name', 'start_date', 'end_date', 'status', 'is_current', 'updated_at']),
            $student?->only(['id', ...StudentService::FIELDS, 'status', 'updated_at']),
            $enrollment?->only(['id', ...StudentEnrollment::AUDIT_FIELDS, 'updated_at']),
            $enrollment ? self::isUsed($enrollment) : false,
        ]));
    }

    public static function data(AcademicYear $year, ?Student $student): array
    {
        $enrollment = $student?->enrollments()->where('academic_year_id', $year->id)->with(['schoolClass', 'section', 'promotionOutcome'])->first();
        $reason = null;
        if ($year->status->value !== 'ACTIVE') {
            $reason = 'This academic year is read-only. Choose an active year to manage enrollment.';
        } elseif ($student && $student->status->value !== 'ACTIVE') {
            $reason = 'Activate this student before changing enrollment.';
        } elseif ($enrollment && ! in_array($enrollment->status->value, ['ACTIVE', 'INACTIVE'], true)) {
            $reason = 'This enrollment was withdrawn or transferred. Its history is read-only.';
        } elseif ($enrollment && ($enrollment->schoolClass?->status->value !== 'ACTIVE' || $enrollment->section?->status->value !== 'ACTIVE')) {
            $reason = 'This class or section is inactive. Existing enrollment details are retained.';
        }
        $classes = SchoolClass::active()->with(['sections' => fn ($sections) => $sections->where('status', 'ACTIVE')->orderBy('name')])->orderBy('sort_order')->orderBy('name')->orderBy('id')->get();
        $stats = StudentEnrollment::where('academic_year_id', $year->id)->selectRaw('class_id, section_id, MAX(roll_number) as last_roll, SUM(status = ?) as occupied', ['ACTIVE'])->groupBy('class_id', 'section_id')->get()->keyBy('section_id');
        $exitReason = null;
        if (! $student || ! $enrollment) {
            $exitReason = 'Save an active enrollment before recording a withdrawal or transfer.';
        } elseif ($year->status->value !== 'ACTIVE') {
            $exitReason = 'Closed academic years are read-only.';
        } elseif ($enrollment->status->value !== 'ACTIVE') {
            $exitReason = 'Only an active enrollment can be withdrawn or transferred.';
        } elseif ($student->status->value !== 'ACTIVE') {
            $exitReason = 'Activate this student before recording a departure.';
        } elseif ($enrollment->promotionOutcome) {
            $exitReason = 'This enrollment already has a recorded outcome.';
        } elseif ($student->enrollments()->whereHas('academicYear', fn ($query) => $query->where('start_date', '>', $year->start_date))->exists()) {
            $exitReason = 'A newer enrollment exists. Select the latest enrollment to record a departure.';
        } elseif ($enrollment->joined_at->isAfter(today())) {
            $exitReason = 'The enrollment joining date is in the future.';
        }

        return [
            'year' => ['id' => $year->id, 'name' => $year->name, 'start' => $year->start_date->format('Y-m-d'), 'end' => $year->end_date->format('Y-m-d'), 'status' => $year->status->value],
            'revision' => self::revision($year, $student, $enrollment),
            'enrollment' => $enrollment ? ['class_id' => $enrollment->class_id, 'section_id' => $enrollment->section_id, 'class_name' => $enrollment->schoolClass?->name, 'section_name' => $enrollment->section?->name, 'roll_number' => $enrollment->roll_number, 'joined_at' => $enrollment->joined_at->format('Y-m-d'), 'status' => $enrollment->status->value] : null,
            'can_write' => $reason === null,
            'can_exit' => $exitReason === null,
            'exit_reason' => $exitReason,
            'departure' => $enrollment?->promotionOutcome && in_array($enrollment->promotionOutcome->action->value, ['WITHDRAW', 'TRANSFER'], true) ? ['action' => $enrollment->promotionOutcome->action->value, 'date' => $enrollment->promotionOutcome->departure_date?->format('Y-m-d'), 'reason' => $enrollment->promotionOutcome->notes, 'destination_school' => $enrollment->promotionOutcome->destination_school] : null,
            'can_correct' => $reason === null && $enrollment && ! self::isUsed($enrollment),
            'reason' => $reason,
            'placement_reason' => $enrollment && self::isUsed($enrollment) ? 'Class and section are locked because marks, results or promotion records depend on this placement. Roll number and joining date can still be updated in an active year.' : null,
            'classes' => $classes->map(fn ($class) => ['id' => $class->id, 'name' => $class->name, 'sections' => $class->sections->map(function ($section) use ($stats) {
                $occupied = (int) ($stats[$section->id]->occupied ?? 0);
                $last = (int) ($stats[$section->id]->last_roll ?? 0);

                return ['id' => $section->id, 'name' => $section->name, 'capacity' => $section->capacity, 'occupied' => $occupied, 'remaining' => $section->capacity === null ? null : max(0, $section->capacity - $occupied), 'suggested_roll' => $last < 4294967295 ? $last + 1 : null];
            })->values()->all()])->values()->all(),
        ];
    }
}
