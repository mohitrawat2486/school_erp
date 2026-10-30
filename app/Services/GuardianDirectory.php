<?php

namespace App\Services;

use App\Models\Guardian;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GuardianDirectory
{
    public function search(array $filters, ?int $studentId = null): LengthAwarePaginator
    {
        $query = Guardian::query();
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $query->where(fn ($s) => $s->whereRaw("CONCAT_WS(' ',first_name,last_name) LIKE ?", ['%'.$q.'%'])->orWhere('phone', 'like', '%'.$q.'%')->orWhere('email', 'like', '%'.$q.'%'));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($studentId !== null) {
            $query->with(['studentLinks' => fn ($q) => $q->where('student_id', $studentId)]);
        }

        return $query->orderBy('first_name')->orderBy('last_name')->orderBy('id')->paginateTable(20)->withQueryString();
    }
}
