<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\Student;
use App\Models\StudentEnrollment;
use Illuminate\Database\Eloquent\Builder;

class EnrollmentSelection
{
    public function select(array $data): array
    {
        $selection = [];
        $context = [];
        foreach (['student_id' => 'student', 'academic_year_id' => 'year', 'class_id' => 'class', 'section_id' => 'section'] as $field => $step) {
            $query = match ($step) {
                'student' => Student::where('status', 'ACTIVE'),'year' => AcademicYear::where('status', 'ACTIVE'),'class' => SchoolClass::active(),'section' => Section::eligible()->where('class_id', $selection['class_id'])
            };
            if (! isset($data[$field])) {
                $q = $data['q'] ?? null;
                if ($q !== null && $q !== '') {
                    $p = '%'.$q.'%';
                    $query->where(function (Builder $s) use ($p, $step) {
                        if ($step === 'student') {
                            $s->where('admission_number', 'like', $p)->orWhereRaw("CONCAT_WS(' ',first_name,middle_name,last_name) LIKE ?", [$p]);
                        } else {
                            $s->where('name', 'like', $p);
                        }
                    });
                }
                if ($step === 'class') {
                    $query->orderBy('sort_order');
                } elseif ($step === 'year') {
                    $query->orderByDesc('start_date');
                }

                return ['items' => $query->orderBy('id')->paginate(20)->withQueryString(), 'step' => $step, 'field' => $field, 'selection' => $selection, 'q' => $q, 'context' => $context];
            }
            $record = $query->whereKey($data[$field])->firstOrFail();
            $selection[$field] = $record->id;
            $context[$step] = $record;
        }

        return ['enrollment' => new StudentEnrollment, 'selection' => $selection, 'context' => $context];
    }
}
