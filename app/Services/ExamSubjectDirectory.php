<?php

namespace App\Services;

use App\Models\ExamSubject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ExamSubjectDirectory
{
    public const RELATIONS = ['exam.academicYear', 'exam.schoolClass', 'subject'];

    public function search(array $filters): LengthAwarePaginator
    {
        $query = ExamSubject::with(self::RELATIONS);
        foreach (['exam_id', 'academic_year_id', 'class_id', 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(fn ($s) => $s->whereHas('subject', fn ($s) => $s->where(fn ($i) => $i->where('name', 'like', $p)->orWhere('code', 'like', $p)))->orWhereHas('exam', fn ($e) => $e->where('name', 'like', $p)));
        }

        return $query->orderByDesc('exam_id')->orderBy('subject_id')->orderBy('id')->paginateTable(20)->withQueryString();
    }
}
