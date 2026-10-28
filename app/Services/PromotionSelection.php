<?php

namespace App\Services;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Models\StudentEnrollment;

class PromotionSelection
{
    public function select(array $data): array
    {
        $mode = $data['mode'] ?? 'PLACEMENT';
        $selection = ['mode' => $mode];
        $context = [];
        if (isset($data['source_enrollment_id'])) {
            $source = StudentEnrollment::whereKey($data['source_enrollment_id'])->firstOrFail();
            $selection['source_enrollment_id'] = $source->id;
            foreach (['academic_year_id', 'class_id', 'section_id'] as $field) {
                $data['source_'.$field] = $source->$field;
            }
        }
        $fields = ['source_academic_year_id', 'source_class_id', 'source_section_id'];
        if ($mode === 'PLACEMENT') {
            $fields = [...$fields, 'target_academic_year_id', 'target_class_id', 'target_section_id'];
        }
        foreach ($fields as $field) {
            $target = str_starts_with($field, 'target_');
            $kind = str_contains($field, 'academic_year') ? 'year' : (str_contains($field, 'class') ? 'class' : 'section');
            $query = match ($kind) {
                'year' => AcademicYear::query(),'class' => SchoolClass::query(),'section' => Section::where('class_id', $selection[$target ? 'target_class_id' : 'source_class_id'])
            };
            if ($kind === 'year') {
                if ($target) {
                    $query->where('status', 'ACTIVE')->where('start_date', '>', $context['source_academic_year_id']->end_date->format('Y-m-d'));
                } else {
                    $query->whereIn('status', $mode === 'EXIT' ? ['ACTIVE'] : ['ACTIVE', 'CLOSED']);
                }
            }
            if ($target && $kind === 'class') {
                $query->active();
            }if ($target && $kind === 'section') {
                $query->eligible();
            }
            if (! isset($data[$field])) {
                $q = $data['q'] ?? null;
                if ($q !== null && $q !== '') {
                    $query->where('name', 'like', '%'.$q.'%');
                }if ($kind === 'year') {
                    $query->orderByDesc('start_date');
                }if ($kind === 'class') {
                    $query->orderBy('sort_order');
                }

                return ['step' => $field, 'kind' => $kind, 'items' => $query->orderBy('id')->paginate(20)->withQueryString(), 'selection' => $selection, 'context' => $context, 'q' => $q];
            }
            $record = $query->whereKey($data[$field])->firstOrFail();
            $selection[$field] = $record->id;
            $context[$field] = $record;
        }
        $query = StudentEnrollment::with(EnrollmentDirectory::RELATIONS)->where('academic_year_id', $selection['source_academic_year_id'])->where('class_id', $selection['source_class_id'])->where('section_id', $selection['source_section_id'])->where('status', 'ACTIVE')->whereDoesntHave('promotionOutcome');
        if ($mode === 'PLACEMENT') {
            $query->whereHas('student', fn ($s) => $s->where('status', 'ACTIVE'));
        }if (isset($selection['source_enrollment_id'])) {
            $query->whereKey($selection['source_enrollment_id']);
        }
        $q = $data['q'] ?? null;
        if ($q !== null && $q !== '') {
            $query->whereHas('student', fn ($s) => $s->where(fn ($i) => $i->where('admission_number', 'like', '%'.$q.'%')->orWhereRaw("CONCAT_WS(' ',first_name,middle_name,last_name) LIKE ?", ['%'.$q.'%'])));
        }

        return ['selection' => $selection, 'context' => $context, 'enrollments' => $query->orderBy('roll_number')->orderBy('id')->paginate(20)->withQueryString(), 'q' => $q];
    }
}
