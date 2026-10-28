<?php

namespace App\Services;

use App\Models\PromotionBatch;
use App\Models\PromotionOutcome;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class PromotionDirectory
{
    public const RELATIONS = ['student', 'sourceEnrollment.academicYear', 'sourceEnrollment.schoolClass', 'sourceEnrollment.section', 'targetEnrollment.academicYear', 'targetEnrollment.schoolClass', 'targetEnrollment.section', 'batch.actor'];

    public function search(array $filters, ?PromotionBatch $batch = null): LengthAwarePaginator
    {
        $query = PromotionOutcome::with(self::RELATIONS);
        if ($batch) {
            $query->where('promotion_batch_id', $batch->id);
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $p = '%'.$q.'%';
            $query->where(fn ($s) => $s->where('action', 'like', $p)->orWhereHas('student', fn ($s) => $s->where(fn ($i) => $i->where('admission_number', 'like', $p)->orWhereRaw("CONCAT_WS(' ',first_name,middle_name,last_name) LIKE ?", [$p]))));
        }

        return $query->orderByDesc('id')->paginate(20)->withQueryString();
    }
}
