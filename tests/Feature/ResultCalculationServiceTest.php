<?php

namespace Tests\Feature;

use App\Enums\GradingSchemeStatus;
use App\Models\ClassSubject;
use App\Models\GradingScheme;
use App\Models\Section;
use App\Models\StudentMark;
use App\Models\User;
use App\Services\ExamService;
use App\Services\ExamSubjectService;
use App\Services\GradingSchemeService;
use App\Services\MarksEntryService;
use App\Services\MarksVerificationService;
use App\Services\ProvisionSchool;
use App\Services\ResultCalculationService;
use App\Support\CurrentSchool;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Concerns\BuildsMarksContext;
use Tests\Concerns\BuildsResultContext;
use Tests\TestCase;

class ResultCalculationServiceTest extends TestCase
{
    use BuildsMarksContext,RefreshDatabase;
    use BuildsResultContext;

    private function calculate(array $c, GradingScheme $s, ?Section $section = null): array
    {
        return app(ResultCalculationService::class)->calculateExam($c['admin'], $c['exam'], $s, $section);
    }

    public function test_locked_marks_calculate_identity_grade_and_rule_snapshots_without_mutation(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $m = $this->locked($c);
        $audit = DB::table('audit_logs')->count();
        $result = $this->calculate($c, $s);
        $r = $result['results'][0];
        $this->assertSame('85.00', $r['obtained_marks']);
        $this->assertSame('PASS', $r['result_status']);
        $this->assertSame('Achieved', $r['grade']);
        $this->assertSame('4.00', $r['grade_point']);
        $this->assertSame(1, $r['rank']);
        $this->assertSame($c['enrollment']->id, $r['enrollment_id']);
        $this->assertSame($m->id, $r['subjects'][0]['marks_id']);
        $this->assertSame(3, $r['subjects'][0]['marks_version']);
        $this->assertSame($s->lock_version, $r['grading_scheme_version']);
        $this->assertSame($audit, DB::table('audit_logs')->count());
        $this->assertSame('MARKS_ENTRY', $c['exam']->fresh()->status->value);
        $this->assertSame(3, $m->fresh()->lock_version);
    }

    public function test_absent_student_fails_and_gets_no_rank(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c, ['is_absent' => true, 'theory_marks' => null, 'practical_marks' => null]);
        $r = $this->calculate($c, $s)['results'][0];
        $this->assertSame('0.00', $r['obtained_marks']);
        $this->assertSame('FAIL', $r['result_status']);
        $this->assertSame('ABSENT', $r['subjects'][0]['result_status']);
        $this->assertSame('Developing', $r['grade']);
        $this->assertNull($r['rank']);
    }

    public function test_missing_draft_submitted_or_verified_rows_prevent_any_calculation(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        foreach (['missing', 'DRAFT', 'SUBMITTED', 'VERIFIED'] as $status) {
            if ($status !== 'missing') {
                if ($status === 'DRAFT') {
                    app(MarksEntryService::class)->save($c['admin'], $c['subject'], $c['section'], $this->payload($c));
                } else {
                    DB::table('student_marks')->update(['status' => $status, 'submitted_by' => $c['admin']->id, 'submitted_at' => now()]);
                }
            }try {
                $this->calculate($c, $s);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_new_active_student_with_missing_marks_blocks_cohort(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $this->enroll($c, 2);
        $this->expectException(ValidationException::class);
        $this->calculate($c, $s);
    }

    public function test_class_and_section_rank_scope_and_ties(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $e = $this->enroll($c, 2);
        $this->locked($c, ['enrollment_id' => $e->id]);
        $b = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'B']);
        $other = $this->enroll($c, 1, $b);
        $bContext = $c;
        $bContext['section'] = $b;
        $this->locked($bContext, ['enrollment_id' => $other->id, 'theory_marks' => 55]);
        $all = $this->calculate($c, $s);
        $this->assertSame('CLASS', $all['rank_scope']);
        $this->assertSame([1, 1, 3], array_column($all['results'], 'rank'));
        $only = $this->calculate($c, $s, $b);
        $this->assertSame('SECTION', $only['rank_scope']);
        $this->assertCount(1, $only['results']);
        $this->assertSame(1, $only['results'][0]['rank']);
    }

    public function test_inactive_students_are_excluded_but_marks_are_retained(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $e = $this->enroll($c, 2);
        $this->locked($c, ['enrollment_id' => $e->id]);
        $e->status = 'INACTIVE';
        $e->save();
        $this->assertCount(1, $this->calculate($c, $s)['results']);
        $this->assertSame(2, StudentMark::count());
    }

    public function test_inactive_context_and_inactive_scheme_reject_calculation(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        foreach ([$c['allocation'], $c['subject'], $c['section'], $c['class'], $c['term']] as $model) {
            $model->status = 'INACTIVE';
            $model->save();
            try {
                $this->calculate($c, $s);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
            $model->status = 'ACTIVE';
            $model->save();
        }app(GradingSchemeService::class)->setStatus($c['admin'], $s, GradingSchemeStatus::Inactive, ['version' => 4]);
        $this->expectException(ValidationException::class);
        $this->calculate($c, $s);
    }

    public function test_wrong_class_section_and_empty_cohort_are_rejected(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $empty = Section::factory()->create(['class_id' => $c['class']->id, 'name' => 'Empty']);
        try {
            $this->calculate($c, $s, $empty);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('roster', $e->errors());
        }
        $wrong = Section::factory()->create();
        $this->expectException(ModelNotFoundException::class);
        $this->calculate($c, $s, $wrong);
    }

    public function test_calculation_permissions_are_independent_of_marks_and_exam_permissions(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        foreach ([['result.view'], ['result.generate'], ['marks.view', 'marks.verify', 'marks.lock'], ['exam.view', 'exam.update']] as $p) {
            $actor = $this->user($c, $p);
            try {
                app(ResultCalculationService::class)->calculateExam($actor, $c['exam'], $s);
                $this->fail();
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $actor = $this->user($c, ['result.view', 'result.generate']);
        $this->assertCount(1, app(ResultCalculationService::class)->calculateExam($actor, $c['exam'], $s)['results']);
    }

    public function test_school_isolation_and_fresh_inactive_actor(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $other = User::factory()->create(['school_id' => null]);
        app(ProvisionSchool::class)->run('OTHER', 'Other', $other->email);
        $other = $other->fresh();
        app(CurrentSchool::class)->set($other->school);
        try {
            app(ResultCalculationService::class)->calculateExam($other, $c['exam'], $s);
            $this->fail();
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }app(CurrentSchool::class)->set($c['admin']->school);
        $fresh = $c['admin']->fresh();
        $fresh->is_active = false;
        $fresh->save();
        $this->expectException(HttpException::class);
        $this->calculate($c, $s);
    }

    public function test_closed_year_or_released_exam_freezes_draft_calculation(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        foreach (['DRAFT', 'OPEN', 'APPROVED', 'PUBLISHED', 'CLOSED'] as $status) {
            $c['exam']->status = $status;
            $c['exam']->save();
            try {
                $this->calculate($c, $s);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('context', $e->errors());
            }
        }
        $c['exam']->status = 'VERIFICATION';
        $c['exam']->save();
        $this->assertCount(1, $this->calculate($c, $s)['results']);
        $c['year']->status = 'CLOSED';
        $c['year']->save();
        $this->expectException(ValidationException::class);
        $this->calculate($c, $s);
    }

    public function test_unlock_after_loading_stale_models_prevents_calculation(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $m = $this->locked($c);
        app(MarksVerificationService::class)->transition($c['admin'], $m, ['action' => 'unlock', 'version' => 3, 'reason' => 'Correction']);
        $this->expectException(ValidationException::class);
        $this->calculate($c, $s);
    }

    public function test_grade_boundaries_use_configured_rules_and_missing_workflow_metadata_fails(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $svc = app(ResultCalculationService::class);
        $this->assertSame('Developing', $svc->calculateGrade($s, '49.99')['grade']);
        $this->assertSame('Achieved', $svc->calculateGrade($s, '50')['grade']);
        $m = $this->locked($c);
        DB::table('student_marks')->where('id', $m->id)->update(['locked_by' => null]);
        $this->expectException(ValidationException::class);
        $this->calculate($c, $s);
    }

    public function test_multi_subject_weights_and_a_failed_subject_override_a_high_aggregate_grade(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        app(ExamService::class)->transition($c['admin'], $c['exam'], 'draft');
        $c['exam'] = $c['exam']->fresh();
        $a = ClassSubject::factory()->create(['academic_year_id' => $c['year']->id, 'class_id' => $c['class']->id]);
        $second = app(ExamSubjectService::class)->save($c['admin'], $c['exam'], null, ['class_subject_id' => $a->id, 'theory_max_marks' => null, 'practical_max_marks' => 50, 'passing_marks' => 20]);
        app(ExamService::class)->transition($c['admin'], $c['exam'], 'open');
        $this->locked($c);
        $other = $c;
        $other['subject'] = $second;
        $this->locked($other, ['theory_marks' => null, 'practical_marks' => 10]);
        $r = $this->calculate($c, $s)['results'][0];
        $this->assertCount(2, $r['subjects']);
        $this->assertSame('150.00', $r['total_max_marks']);
        $this->assertSame('95.00', $r['obtained_marks']);
        $this->assertSame('63.33', $r['percentage']);
        $this->assertSame('Achieved', $r['grade']);
        $this->assertSame('FAIL', $r['result_status']);
        $this->assertNull($r['rank']);
    }

    public function test_ranking_spans_roster_chunks_without_resetting_at_one_hundred_students(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $c['section']->capacity = null;
        $c['section']->save();
        $m = $this->locked($c);
        $base = $m->getAttributes();
        unset($base['id']);
        for ($i = 2; $i <= 101; $i++) {
            $e = $this->enroll($c, $i);
            $row = array_replace($base, ['student_id' => $e->student_id, 'enrollment_id' => $e->id]);
            if ($i === 101) {
                $row['theory_marks'] = '65.00';
                $row['total_marks'] = '90.00';
            }DB::table('student_marks')->insert($row);
        }
        $r = $this->calculate($c, $s)['results'];
        $this->assertCount(101, $r);
        $this->assertSame(2, $r[0]['rank']);
        $this->assertSame(1, $r[100]['rank']);
    }

    public function test_returned_grade_values_do_not_change_when_configuration_is_later_revised(): void
    {
        $c = $this->context();
        $s = $this->scheme($c);
        $this->locked($c);
        $before = $this->calculate($c, $s);
        $svc = app(GradingSchemeService::class);
        $svc->setStatus($c['admin'], $s, GradingSchemeStatus::Inactive, ['version' => 4]);
        $rule = $s->rules()->where('grade', 'Achieved')->first();
        $svc->saveRule($c['admin'], $s, $rule, ['version' => 5, 'min_percentage' => 50, 'max_percentage' => 100, 'grade' => 'Revised', 'grade_point' => 5]);
        $svc->setStatus($c['admin'], $s, GradingSchemeStatus::Active, ['version' => 6]);
        $after = $this->calculate($c, $s);
        $this->assertSame('Achieved', $before['results'][0]['grade']);
        $this->assertSame('Revised', $after['results'][0]['grade']);
        $this->assertSame(4, $before['grading_scheme_version']);
        $this->assertSame(7, $after['grading_scheme_version']);
    }
}
