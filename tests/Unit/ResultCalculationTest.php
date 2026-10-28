<?php

namespace Tests\Unit;

use App\Services\ResultCalculationService;
use App\Support\ResultMath;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ResultCalculationTest extends TestCase
{
    private function service(): ResultCalculationService
    {
        return app(ResultCalculationService::class);
    }

    public static function percentages(): array
    {
        return [['0', '100', '0.00'], ['100', '100', '100.00'], ['1', '3', '33.33'], ['2', '3', '66.67'], ['1', '32', '3.13'], ['99.99', '100', '99.99'], ['0.01', '19999.98', '0.00'], ['19999.98', '19999.98', '100.00'], ['9999999999.99', '10000000000', '100.00']];
    }

    #[DataProvider('percentages')]
    public function test_percentage_uses_exact_half_up_rounding(mixed $a, mixed $b, string $expected): void
    {
        $this->assertSame($expected, $this->service()->calculatePercentage($a, $b));
    }

    public static function invalidNumbers(): array
    {
        return [[null], [true], [-1], ['-0.01'], ['1.001'], ['1e2'], ['NaN'], ['INF'], [''], [' 1'], ['1,00'], ['10000000000.01'], [[]]];
    }

    #[DataProvider('invalidNumbers')]
    public function test_invalid_decimals_are_rejected(mixed $value): void
    {
        $this->expectException(ValidationException::class);
        ResultMath::units($value);
    }

    public function test_invalid_percentage_ratio_is_rejected(): void
    {
        foreach ([[0, 0], [101, 100]] as [$a,$b]) {
            try {
                $this->service()->calculatePercentage($a, $b);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('marks', $e->errors());
            }
        }
    }

    private function config(array $extra = []): array
    {
        return array_replace(['theory_max_marks' => '70.00', 'practical_max_marks' => '30.00', 'passing_marks' => '33.00'], $extra);
    }

    private function marks(array $extra = []): array
    {
        return array_replace(['theory_marks' => '60.00', 'practical_marks' => '25.00', 'is_absent' => false], $extra);
    }

    public function test_normal_subject_has_exact_components_total_percentage_and_status(): void
    {
        $r = $this->service()->calculateSubjectResult($this->marks(), $this->config());
        $this->assertSame('85.00', $r['obtained_marks']);
        $this->assertSame('100.00', $r['max_marks']);
        $this->assertSame('85.00', $r['percentage']);
        $this->assertSame('PASS', $r['result_status']);
    }

    public function test_absence_counts_zero_with_maximum_retained_and_never_passes_zero_threshold(): void
    {
        $r = $this->service()->calculateSubjectResult($this->marks(['theory_marks' => null, 'practical_marks' => null, 'is_absent' => true]), $this->config(['passing_marks' => 0]));
        $this->assertSame('ABSENT', $r['result_status']);
        $this->assertSame('0.00', $r['obtained_marks']);
        $this->assertSame('100.00', $r['max_marks']);
        $this->assertSame('0.00', $r['percentage']);
    }

    public function test_passing_boundary_uses_unrounded_marks(): void
    {
        foreach ([['32.99', 'FAIL'], ['33', 'PASS'], ['33.01', 'PASS']] as [$score,$expected]) {
            $this->assertSame($expected, $this->service()->calculateSubjectResult(['theory_marks' => $score, 'is_absent' => false], $this->config(['theory_max_marks' => 100, 'practical_max_marks' => null]))['result_status']);
        }$this->assertSame('FAIL', $this->service()->calculatePassFail('32.99', '33'));
    }

    public function test_zero_marks_and_zero_threshold_are_valid(): void
    {
        $r = $this->service()->calculateSubjectResult($this->marks(['theory_marks' => 0, 'practical_marks' => 0]), $this->config(['passing_marks' => 0]));
        $this->assertSame('PASS', $r['result_status']);
        $this->assertSame('0.00', $r['obtained_marks']);
    }

    public function test_theory_practical_only_and_zero_max_component(): void
    {
        foreach ([['theory_max_marks' => null, 'practical_max_marks' => 100, 'theory_marks' => null, 'practical_marks' => 75], ['theory_max_marks' => 100, 'practical_max_marks' => null, 'theory_marks' => 75, 'practical_marks' => null], ['theory_max_marks' => 0, 'practical_max_marks' => 100, 'theory_marks' => 0, 'practical_marks' => 75]] as $data) {
            $r = $this->service()->calculateSubjectResult($this->marks($data), $this->config($data));
            $this->assertSame('75.00', $r['obtained_marks']);
        }
    }

    public function test_maximum_components_and_exact_decimal_addition(): void
    {
        $r = $this->service()->calculateSubjectResult($this->marks(['theory_marks' => '9999.99', 'practical_marks' => '9999.99']), $this->config(['theory_max_marks' => '9999.99', 'practical_max_marks' => '9999.99', 'passing_marks' => '9999.99']));
        $this->assertSame('19999.98', $r['obtained_marks']);
        $this->assertSame('100.00', $r['percentage']);
        $r = $this->service()->calculateSubjectResult($this->marks(['theory_marks' => '99.99', 'practical_marks' => '0.01']), $this->config(['theory_max_marks' => '99.99', 'practical_max_marks' => '0.01', 'passing_marks' => 100]));
        $this->assertSame('100.00', $r['obtained_marks']);
    }

    public function test_missing_over_max_absent_numeric_and_unused_component_fail(): void
    {
        foreach ([['theory_marks' => null], ['theory_marks' => 71], ['practical_marks' => 31], ['is_absent' => true], ['is_absent' => 1]] as $bad) {
            try {
                $this->service()->calculateSubjectResult($this->marks($bad), $this->config());
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }try {
            $this->service()->calculateSubjectResult($this->marks(), $this->config(['practical_max_marks' => null]));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('practical_marks', $e->errors());
        }
    }

    public function test_invalid_configuration_is_rejected(): void
    {
        foreach ([['theory_max_marks' => 0, 'practical_max_marks' => 0], ['passing_marks' => 101], ['passing_marks' => null], ['theory_max_marks' => '70.001'], ['theory_max_marks' => 10000]] as $bad) {
            try {
                $this->service()->calculateSubjectResult($this->marks(), $this->config($bad));
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    public function test_weighted_totals_not_average_of_percentages(): void
    {
        $one = $this->service()->calculateSubjectResult(['theory_marks' => 50, 'is_absent' => false], ['theory_max_marks' => 50, 'passing_marks' => 20]);
        $two = $this->service()->calculateSubjectResult(['theory_marks' => 50, 'is_absent' => false], ['theory_max_marks' => 150, 'passing_marks' => 40]);
        $r = $this->service()->calculateTotal([$one, $two]);
        $this->assertSame('200.00', $r['total_max_marks']);
        $this->assertSame('100.00', $r['obtained_marks']);
        $this->assertSame('50.00', $r['percentage']);
        $this->assertSame('PASS', $r['result_status']);
    }

    public function test_one_failed_or_absent_subject_fails_overall_even_with_high_percentage(): void
    {
        $pass = $this->service()->calculateSubjectResult($this->marks(), $this->config());
        $fail = $this->service()->calculateSubjectResult($this->marks(['theory_marks' => 10, 'practical_marks' => 0]), $this->config());
        $absent = $this->service()->calculateSubjectResult(['is_absent' => true], $this->config());
        foreach ([$fail, $absent] as $r) {
            $this->assertSame('FAIL', $this->service()->calculateTotal([$pass, $r])['result_status']);
        }$this->assertSame('42.50', $this->service()->calculateTotal([$pass, $absent])['percentage']);
    }

    public function test_totals_reject_empty_or_tampered_subject_status(): void
    {
        foreach ([[], [['max_marks' => 100, 'obtained_marks' => 20, 'passing_marks' => 33, 'is_absent' => false, 'result_status' => 'PASS']]] as $rows) {
            try {
                $this->service()->calculateTotal($rows);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
    }

    private function ranked(int $id, string $score, string $status = 'PASS'): array
    {
        return ['enrollment_id' => $id, 'obtained_marks' => $score, 'total_max_marks' => '100.00', 'result_status' => $status];
    }

    public function test_competition_ranking_ties_failed_students_and_input_order(): void
    {
        $r = $this->service()->calculateRank([$this->ranked(4, '70'), $this->ranked(2, '90'), $this->ranked(3, '99', 'FAIL'), $this->ranked(1, '90')]);
        $this->assertSame([3, 1, null, 1], array_column($r, 'rank'));
        $this->assertSame([4, 2, 3, 1], array_column($r, 'enrollment_id'));
    }

    public function test_ranking_uses_exact_scores_even_when_percentages_round_to_same_value(): void
    {
        $a = $this->ranked(1, '9999.98');
        $b = $this->ranked(2, '9999.99');
        $a['total_max_marks'] = $b['total_max_marks'] = '19999.98';
        $this->assertSame($this->service()->calculatePercentage($a['obtained_marks'], $a['total_max_marks']), $this->service()->calculatePercentage($b['obtained_marks'], $b['total_max_marks']));
        $this->assertSame([2, 1], array_column($this->service()->calculateRank([$a, $b]), 'rank'));
    }

    public function test_ranking_rejects_duplicates_mixed_maxima_invalid_scores_and_status(): void
    {
        $a = $this->ranked(1, '50');
        foreach ([[$a, $a], [$a, array_replace($this->ranked(2, '50'), ['total_max_marks' => 200])], [array_replace($a, ['obtained_marks' => 101])], [array_replace($a, ['result_status' => 'UNKNOWN'])]] as $rows) {
            try {
                $this->service()->calculateRank($rows);
                $this->fail();
            } catch (ValidationException $e) {
                $this->assertNotEmpty($e->errors());
            }
        }
        $this->assertSame([], $this->service()->calculateRank([]));
    }

    public function test_rounding_up_percentage_never_turns_below_threshold_marks_into_pass(): void
    {
        $r = $this->service()->calculateSubjectResult($this->marks(['theory_marks' => '6599.99', 'practical_marks' => 0]), $this->config(['theory_max_marks' => '9999.99', 'practical_max_marks' => '9999.99', 'passing_marks' => '6600.00']));
        $this->assertSame('33.00', $r['percentage']);
        $this->assertSame('FAIL', $r['result_status']);
    }
}
