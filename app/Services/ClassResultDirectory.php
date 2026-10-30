<?php

namespace App\Services;

use App\Models\StudentResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ClassResultDirectory
{
    public const CONTEXT = ['academic_year_id', 'exam_id', 'class_id', 'section_id'];

    public function base(): Builder
    {
        return StudentResult::whereHas('resultBatch', fn ($q) => $q->where('status', 'PUBLISHED')->whereNotNull('snapshots_completed_at'));
    }

    public function validate(array $data, bool $required = true): array
    {
        $rules = ['q' => 'nullable|string|max:100', 'result_status' => ['nullable', Rule::in(['PASS', 'FAIL'])]];
        foreach (self::CONTEXT as $f) {
            $rules[$f] = ($required && $f !== 'section_id' ? 'required' : 'nullable').'|integer|min:1';
        }
        $filters = Validator::make($data, $rules)->validate();
        $query = $this->base();
        $missing = false;
        foreach (self::CONTEXT as $f) {
            if ($filters[$f] ?? null) {
                if ($missing) {
                    throw ValidationException::withMessages([$f => 'Choose earlier filters before this context.']);
                }
                $query->where($f, $filters[$f]);
                if (! (clone $query)->exists()) {
                    throw ValidationException::withMessages([$f => 'Choose a published snapshot context matching the earlier filters.']);
                }
            } else {
                $missing = true;
            }
        }

        return $filters;
    }

    public function query(array $filters, bool $classOnly = false): Builder
    {
        $q = $this->base();
        foreach (self::CONTEXT as $f) {
            if (($filters[$f] ?? null) && ! ($classOnly && $f === 'section_id')) {
                $q->where($f, $filters[$f]);
            }
        }
        if (! $classOnly) {
            if ($filters['q'] ?? null) {
                $q->where('student_name', 'like', '%'.$filters['q'].'%');
            }
            if ($filters['result_status'] ?? null) {
                $q->where('result_status', $filters['result_status']);
            }
        }

        return $q->orderBy('section_name')->orderBy('roll_number')->orderBy('enrollment_id');
    }

    public function choices(array $filters, string $field, string $name, ?string $search = null)
    {
        $q = $this->base();
        foreach (self::CONTEXT as $f) {
            if ($f === $field) {
                break;
            }
            if ($filters[$f] ?? null) {
                $q->where($f, $filters[$f]);
            }
        }
        if ($search !== null && $search !== '') {
            $q->where($name, 'like', '%'.$search.'%');
        }

        return $q->select($field)->selectRaw('MIN('.$name.') as name')->groupBy($field)->orderBy($field)->paginateTable(20, ['*'], 'page', [$name])->withQueryString();
    }

    public function output(array $filters, string $scope, array $ids = [])
    {
        Validator::make(['scope' => $scope, 'ids' => $ids], ['scope' => 'required|in:filtered,selected,class', 'ids' => 'array|max:1000', 'ids.*' => 'required|integer|min:1|distinct'])->validate();
        $q = $this->query($filters, $scope === 'class');
        if ($scope === 'selected') {
            if (! $ids) {
                throw ValidationException::withMessages(['ids' => 'Select at least one student on this page.']);
            }
            $q->whereIn('id', $ids);
        }
        $rows = $q->limit(5001)->get();
        if ($scope === 'selected' && $rows->count() !== count($ids)) {
            throw ValidationException::withMessages(['ids' => 'Selected results must belong to the current published filters.']);
        }
        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['results' => 'No results match this output.']);
        }
        if ($rows->count() > 5000) {
            throw ValidationException::withMessages(['results' => 'Choose a smaller section or selection; a single output supports up to 5000 students.']);
        }

        return $rows;
    }
}
