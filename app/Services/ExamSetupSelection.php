<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\Exam;
use App\Models\ExamTerm;
use App\Models\SchoolClass;

class ExamSetupSelection
{
    public function select(array $data, bool $term): array
    {
        $selection = [];
        $context = [];
        foreach ($term ? ['academic_year_id' => 'year'] : ['academic_year_id' => 'year', 'exam_term_id' => 'term', 'class_id' => 'class'] as $field => $step) {
            $query = match ($step) {
                'year' => AcademicYear::whereIn('status', ['DRAFT', 'ACTIVE']),'term' => ExamTerm::where('academic_year_id', $selection['academic_year_id'])->where('status', 'ACTIVE'),'class' => SchoolClass::active()
            };
            if (! isset($data[$field])) {
                $q = $data['q'] ?? null;
                if ($q !== null && $q !== '') {
                    $query->where('name', 'like', '%'.$q.'%');
                }
                if ($step === 'year') {
                    $query->orderByDesc('start_date');
                }
                if ($step === 'class') {
                    $query->orderBy('sort_order');
                }

                return ['items' => $query->orderBy('id')->paginateTable(20)->withQueryString(), 'step' => $step, 'field' => $field, 'selection' => $selection, 'context' => $context, 'q' => $q, 'forTerm' => $term];
            }
            $record = $query->whereKey($data[$field])->firstOrFail();
            $selection[$field] = $record->id;
            $context[$step] = $record;
        }

        return ['selection' => $selection, 'context' => $context, 'record' => $term ? new ExamTerm : new Exam];
    }
}
