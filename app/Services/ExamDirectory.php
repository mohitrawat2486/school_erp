<?php

namespace App\Services;

use App\Models\Exam;
use App\Models\ExamTerm;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ExamDirectory
{
    public const RELATIONS = ['academicYear', 'examTerm', 'schoolClass'];

    public function search(array $filters, bool $terms = false): LengthAwarePaginator
    {
        $query = $terms ? ExamTerm::with('academicYear') : Exam::with(self::RELATIONS);
        foreach ($terms ? ['academic_year_id', 'status'] : ['academic_year_id', 'exam_term_id', 'class_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(function ($s) use ($p, $terms) {
                $s->where('name', 'like', $p)->orWhereHas('academicYear', fn ($y) => $y->where('name', 'like', $p));
                if (! $terms) {
                    $s->orWhereHas('examTerm', fn ($t) => $t->where('name', 'like', $p))->orWhereHas('schoolClass', fn ($c) => $c->where('name', 'like', $p));
                }
            });
        }

        return $query->orderByDesc('academic_year_id')->orderBy('name')->orderBy('id')->paginate(20)->withQueryString();
    }
}
