<?php

namespace App\Support;

use App\Enums\AcademicYearStatus;
use App\Models\AcademicYear;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class StudentEnrollmentForm
{
    public static function data(Request $request, Student $student): array
    {
        $current = app(CurrentSchool::class)->academicYear();
        $years = AcademicYear::where(function ($query) use ($student, $current) {
            $query->where('status', 'ACTIVE');
            if ($current) {
                $query->orWhereKey($current->id);
            }
            if ($student->exists) {
                $query->orWhereHas('enrollments', fn ($enrollments) => $enrollments->where('student_id', $student->id));
            }
        })->orderByDesc('start_date')->orderByDesc('id')->get();
        $oldYear = filter_var($request->old('enrollment.academic_year_id'), FILTER_VALIDATE_INT);
        if (in_array($request->old('departure.action'), ['WITHDRAW', 'TRANSFER'], true)) {
            $oldYear = filter_var($request->old('departure.academic_year_id'), FILTER_VALIDATE_INT);
        }
        $requestedYear = filter_var($request->query('enrollment_year'), FILTER_VALIDATE_INT);
        $selected = $years->firstWhere('id', $oldYear) ?? $years->firstWhere('id', $requestedYear) ?? $years->firstWhere('id', $current?->id) ?? $years->firstWhere('status', AcademicYearStatus::Active);
        $state = $selected ? StudentEnrollmentState::data($selected, $student->exists ? $student : null) : null;
        $draft = null;
        if ($state && $oldYear === $selected->id && ! $request->session()->get('errors')?->has('enrollment.revision')) {
            $old = $request->old('enrollment');
            if (is_array($old)) {
                $draft = array_filter(array_intersect_key($old, array_flip(['action', 'class_id', 'section_id', 'roll_mode', 'roll_number', 'joined_at', 'revision', 'correct_placement', 'reactivate'])), fn ($value) => is_scalar($value) || $value === null);
            }
        }

        $departure = null;
        if ($state && $state['can_exit'] && $oldYear === $selected->id && ! $request->session()->get('errors')?->has('departure.revision')) {
            $old = $request->old('departure');
            if (is_array($old) && in_array($old['action'] ?? null, ['WITHDRAW', 'TRANSFER'], true)) {
                $departure = array_filter(array_intersect_key($old, array_flip(['action', 'academic_year_id', 'revision', 'operation_key', 'departure_date', 'reason', 'destination_school', 'confirmed'])), fn ($value) => is_scalar($value) || $value === null);
            }
        }

        return ['enrollmentYears' => $years, 'enrollmentState' => $state, 'enrollmentDraft' => $draft, 'departureDraft' => $departure, 'departureOperationKey' => (string) Str::uuid()];
    }
}
