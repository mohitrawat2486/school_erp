<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\GradeRule;
use App\Models\GradingScheme;
use App\Models\School;
use App\Models\Section;
use App\Models\StudentEnrollment;
use App\Models\StudentMark;
use App\Models\User;
use App\Support\CurrentSchool;
use App\Support\ResultMath;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ResultCalculationService
{
    public function calculatePercentage(mixed $obtained, mixed $maximum): string
    {
        return ResultMath::percentage($obtained, $maximum);
    }

    public function calculateGrade(GradingScheme $scheme, mixed $percentage): array
    {
        return $this->gradeSnapshot(app(GradeResolver::class)->resolve($scheme, $percentage));
    }

    private function gradeSnapshot(GradeRule $rule): array
    {
        return ['grade_rule_id' => $rule->id, 'grade' => $rule->grade, 'grade_point' => $rule->grade_point, 'grade_min_percentage' => $rule->min_percentage, 'grade_max_percentage' => $rule->max_percentage, 'grade_remarks' => $rule->remarks];
    }

    private function gradeFromRules(array $rules, string $percentage): array
    {
        $value = ResultMath::units($percentage, 'percentage', 10000);
        $matches = [];
        foreach ($rules as $rule) {
            if (ResultMath::units($rule->min_percentage) <= $value && ResultMath::units($rule->max_percentage) >= $value) {
                $matches[] = $rule;
            }
        }
        if (count($matches) !== 1) {
            throw ValidationException::withMessages(['grading' => 'The configured grade ranges do not resolve this percentage uniquely.']);
        }

        return $this->gradeSnapshot($matches[0]);
    }

    public function calculatePassFail(mixed $obtained, mixed $passing, bool $absent = false): string
    {
        $a = ResultMath::units($obtained);
        $b = ResultMath::units($passing, 'passing_marks');

        return $absent ? 'ABSENT' : ($a >= $b ? 'PASS' : 'FAIL');
    }

    /** Pure component calculation. The authorized database workflow supplies these arrays. */
    public function calculateSubjectResult(array $marks, array $configuration): array
    {
        if (! array_key_exists('is_absent', $marks) || ! is_bool($marks['is_absent'])) {
            throw ValidationException::withMessages(['is_absent' => 'An explicit boolean absence flag is required.']);
        }
        $maximum = 0;
        $obtained = 0;
        $absent = $marks['is_absent'];
        $components = [];
        foreach (['theory', 'practical'] as $part) {
            $max = $configuration[$part.'_max_marks'] ?? null;
            $value = $marks[$part.'_marks'] ?? null;
            if ($max === null) {
                if ($value !== null) {
                    throw ValidationException::withMessages([$part.'_marks' => 'Unused components cannot contain marks.']);
                }
                $components[$part.'_marks'] = null;
                $components[$part.'_max_marks'] = $max === null ? null : ResultMath::decimal(ResultMath::units($max, $part.'_max_marks', 999999));

                continue;
            }
            $maxUnits = ResultMath::units($max, $part.'_max_marks', 999999);
            $maximum += $maxUnits;
            if ($absent) {
                if ($value !== null) {
                    throw ValidationException::withMessages([$part.'_marks' => 'Absent students cannot have numeric component marks.']);
                }
                $components[$part.'_marks'] = null;
                $components[$part.'_max_marks'] = $max === null ? null : ResultMath::decimal(ResultMath::units($max, $part.'_max_marks', 999999));

                continue;
            }
            $units = ResultMath::units($value, $part.'_marks', 999999);
            if ($units > $maxUnits) {
                throw ValidationException::withMessages([$part.'_marks' => 'Marks exceed the configured component maximum.']);
            }
            $obtained += $units;
            $components[$part.'_marks'] = ResultMath::decimal($units);
            $components[$part.'_max_marks'] = ResultMath::decimal($maxUnits);
        }
        $passing = ResultMath::units($configuration['passing_marks'] ?? null, 'passing_marks', 999999);
        if ($maximum === 0 || $passing > $maximum) {
            throw ValidationException::withMessages(['configuration' => 'Configure positive maximum marks and passing marks within the maximum.']);
        }
        $status = $this->calculatePassFail(ResultMath::decimal($obtained), ResultMath::decimal($passing), $absent);

        return $components + ['max_marks' => ResultMath::decimal($maximum), 'obtained_marks' => ResultMath::decimal($obtained), 'percentage' => $this->calculatePercentage(ResultMath::decimal($obtained), ResultMath::decimal($maximum)), 'passing_marks' => ResultMath::decimal($passing), 'is_absent' => $absent, 'result_status' => $status];
    }

    public function calculateTotal(array $subjects): array
    {
        if (! $subjects) {
            throw ValidationException::withMessages(['subjects' => 'At least one complete subject result is required.']);
        }
        $maximum = 0;
        $obtained = 0;
        $passed = true;
        foreach ($subjects as $subject) {
            $max = ResultMath::units($subject['max_marks'] ?? null);
            $value = ResultMath::units($subject['obtained_marks'] ?? null);
            $passing = ResultMath::units($subject['passing_marks'] ?? null);
            $absent = $subject['is_absent'] ?? null;
            if (! is_bool($absent) || $max === 0 || $value > $max || $passing > $max || ($absent && $value !== 0)) {
                throw ValidationException::withMessages(['subjects' => 'Invalid subject values for aggregation.']);
            }
            $status = $this->calculatePassFail($subject['obtained_marks'], $subject['passing_marks'], $absent);
            if (($subject['result_status'] ?? null) !== $status) {
                throw ValidationException::withMessages(['subjects' => 'Subject status does not match its marks.']);
            }
            $maximum += $max;
            $obtained += $value;
            if ($maximum > ResultMath::MAX_UNITS) {
                throw ValidationException::withMessages(['subjects' => 'Total marks exceed the supported calculation limit.']);
            }
            $passed = $passed && $status === 'PASS';
        }

        return ['total_max_marks' => ResultMath::decimal($maximum), 'obtained_marks' => ResultMath::decimal($obtained), 'percentage' => $this->calculatePercentage(ResultMath::decimal($obtained), ResultMath::decimal($maximum)), 'result_status' => $passed ? 'PASS' : 'FAIL'];
    }

    /** Competition ranking for a single cohort with identical maximum marks. */
    public function calculateRank(array $results): array
    {
        $seen = [];
        $maximum = null;
        $eligible = [];
        foreach ($results as $i => $result) {
            $id = $result['enrollment_id'] ?? null;
            $max = ResultMath::units($result['total_max_marks'] ?? null);
            $value = ResultMath::units($result['obtained_marks'] ?? null);
            if (! is_int($id) || $id < 1 || isset($seen[$id]) || $max === 0 || $value > $max || ($maximum !== null && $maximum !== $max) || ! in_array($result['result_status'] ?? null, ['PASS', 'FAIL'], true)) {
                throw ValidationException::withMessages(['ranking' => 'Rank a single cohort with distinct enrollments, valid results and identical maximum marks.']);
            }
            $seen[$id] = true;
            $maximum = $max;
            $results[$i]['rank'] = null;
            if ($result['result_status'] === 'PASS') {
                $eligible[] = ['index' => $i, 'score' => $value, 'id' => $id];
            }
        }
        usort($eligible, fn ($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['id'] <=> $b['id']));
        $previous = null;
        $rank = 0;
        foreach ($eligible as $position => $entry) {
            if ($previous !== $entry['score']) {
                $rank = $position + 1;
            }
            $results[$entry['index']]['rank'] = $rank;
            $previous = $entry['score'];
        }

        return $results;
    }

    /** Read-only calculation draft; approval, persistence and publication are later phases. */
    public function calculateExam(User $actor, Exam $exam, GradingScheme $scheme, ?Section $section = null): array
    {
        foreach ([$exam, $scheme, ...($section ? [$section] : [])] as $model) {
            abort_unless((int) $model->school_id === app(CurrentSchool::class)->id(), 403);
        }

        return DB::transaction(function () use ($actor, $exam, $scheme, $section) {
            School::whereKey(app(CurrentSchool::class)->id())->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            abort_unless($actor && $actor->can('result.view') && $actor->can('result.generate'), 403);
            $exam = Exam::with(['academicYear', 'examTerm', 'schoolClass'])->findOrFail($exam->id);
            $scheme = GradingScheme::findOrFail($scheme->id);
            if (! in_array($exam->status->value, ['MARKS_ENTRY', 'VERIFICATION'], true) || $exam->academicYear->status->value !== 'ACTIVE' || $exam->examTerm->status->value !== 'ACTIVE' || $exam->schoolClass->status->value !== 'ACTIVE' || $scheme->status->value !== 'ACTIVE') {
                throw ValidationException::withMessages(['context' => 'Calculation requires an active year, exam context and grading scheme, with the exam in marks entry or verification.']);
            }
            if ($section) {
                $section = Section::where('class_id', $exam->class_id)->findOrFail($section->id);
                if (! $section->isEligible()) {
                    throw ValidationException::withMessages(['section' => 'Choose an active section.']);
                }
            }
            app(ExamSubjectService::class)->assertReady($exam);
            app(GradingSchemeService::class)->assertComplete($scheme);
            $rules = $scheme->rules()->orderBy('min_percentage')->get()->all();
            $subjects = $exam->examSubjects()->with('subject')->where('status', 'ACTIVE')->orderBy('subject_id')->get();
            $roster = StudentEnrollment::with(['student', 'section.schoolClass'])->where('academic_year_id', $exam->academic_year_id)->where('class_id', $exam->class_id)->where('status', 'ACTIVE')->whereHas('student', fn ($q) => $q->where('status', 'ACTIVE'));
            if ($section) {
                $roster->where('section_id', $section->id);
            }
            $results = [];
            $roster->chunkById(100, function ($enrollments) use (&$results, $exam, $scheme, $subjects, $rules) {
                $marks = StudentMark::where('exam_id', $exam->id)->whereIn('enrollment_id', $enrollments->pluck('id'))->whereIn('exam_subject_id', $subjects->pluck('id'))->get()->groupBy('enrollment_id');
                foreach ($enrollments as $enrollment) {
                    if (! $enrollment->section->isEligible()) {
                        throw ValidationException::withMessages(['enrollment' => 'An active enrollment belongs to an inactive section.']);
                    }
                    $rows = [];
                    foreach ($subjects as $subject) {
                        $mark = ($marks->get($enrollment->id) ?? collect())->firstWhere('exam_subject_id', $subject->id);
                        if (! $mark || $mark->status->value !== 'LOCKED' || ! $mark->submitted_by || ! $mark->submitted_at || ! $mark->verified_by || ! $mark->verified_at || ! $mark->locked_by || ! $mark->locked_at || (int) $mark->student_id !== $enrollment->student_id) {
                            throw ValidationException::withMessages(['marks' => 'Every active student and exam subject requires locked marks with complete workflow metadata.']);
                        }
                        $row = $this->calculateSubjectResult($mark->only(['theory_marks', 'practical_marks', 'is_absent']), $subject->only(['theory_max_marks', 'practical_max_marks', 'passing_marks']));
                        if ($mark->total_marks !== ($row['is_absent'] ? null : $row['obtained_marks'])) {
                            throw ValidationException::withMessages(['marks' => 'Saved total marks do not match their components.']);
                        }
                        $rows[] = $row + $this->gradeFromRules($rules, $row['percentage']) + ['subject_id' => $subject->subject_id, 'exam_subject_id' => $subject->id, 'subject_name' => $subject->subject->name, 'marks_id' => $mark->id, 'marks_version' => $mark->lock_version, 'remarks' => $mark->remarks];
                    }
                    $total = $this->calculateTotal($rows);
                    $results[] = $total + $this->gradeFromRules($rules, $total['percentage']) + ['school_id' => $exam->school_id, 'academic_year_id' => $exam->academic_year_id, 'exam_id' => $exam->id, 'class_id' => $exam->class_id, 'section_id' => $enrollment->section_id, 'student_id' => $enrollment->student_id, 'enrollment_id' => $enrollment->id, 'student_name' => $enrollment->student->fullName(), 'admission_number' => $enrollment->student->admission_number, 'date_of_birth' => $enrollment->student->date_of_birth?->format('Y-m-d'), 'roll_number' => $enrollment->roll_number, 'grading_scheme_id' => $scheme->id, 'grading_scheme_name' => $scheme->name, 'grading_scheme_version' => $scheme->lock_version, 'subjects' => $rows];
                }
            });
            if (! $results) {
                throw ValidationException::withMessages(['roster' => 'No active students are enrolled for this calculation cohort.']);
            }

            return ['exam_id' => $exam->id, 'grading_scheme_id' => $scheme->id, 'grading_scheme_version' => $scheme->lock_version, 'rank_scope' => $section ? 'SECTION' : 'CLASS', 'section_id' => $section?->id, 'results' => $this->calculateRank($results)];
        });
    }
}
