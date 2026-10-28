<?php

namespace App\Services;

use App\Models\StudentEnrollment;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class EnrollmentDirectory
{
    public const RELATIONS = ['student', 'academicYear', 'schoolClass', 'section'];

    public function search(array $filters): LengthAwarePaginator
    {
        $query = StudentEnrollment::with(self::RELATIONS);
        foreach ([...StudentEnrollment::CONTEXT, 'status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(function ($s) use ($p) {
                $s->whereHas('student', fn ($student) => $student->where(fn ($i) => $i->where('admission_number', 'like', $p)->orWhereRaw("CONCAT_WS(' ',first_name,middle_name,last_name) LIKE ?", [$p])))->orWhereHas('academicYear', fn ($y) => $y->where('name', 'like', $p))->orWhereHas('schoolClass', fn ($c) => $c->where('name', 'like', $p))->orWhereHas('section', fn ($c) => $c->where('name', 'like', $p));
            });
        }

        return $query->orderByDesc('academic_year_id')->orderBy('class_id')->orderBy('section_id')->orderBy('roll_number')->orderBy('id')->paginate(20)->withQueryString();
    }
}
