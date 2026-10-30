<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use WeakMap;

/** Keeps existing authorised index queries and Blade row rendering authoritative. */
final class TablePagination
{
    private WeakMap $tables;

    public function __construct()
    {
        $this->tables = new WeakMap;
    }

    public function paginate(Builder $query, int $default = 20, array $columns = ['*'], string $pageName = 'page', ?array $fields = null): LengthAwarePaginator
    {
        $input = request()->query('tables', []);
        $data = Validator::make(['tables' => $input], [
            'tables' => 'array|max:10',
            'tables.*' => 'array:search,length',
            'tables.*.search' => 'nullable|string|max:100',
            'tables.*.length' => ['sometimes', 'integer', Rule::in([10, 20, 25, 50, 100])],
        ])->validate();
        $state = $data['tables'][$pageName] ?? [];
        $term = trim($state['search'] ?? '');
        $length = (int) ($state['length'] ?? $default);
        $fields ??= config('list-tables.'.$query->getModel()->getTable(), []);
        $total = $term === '' ? null : (clone $query)->toBase()->getCountForPagination();
        if ($term !== '' && $fields !== []) {
            $this->search($query, $fields, $term);
        }
        $records = $query->paginate($length, $columns, $pageName)->withQueryString();
        $this->tables[$records] = [
            'key' => $pageName, 'length' => $length, 'page' => $records->currentPage(),
            'total' => $total ?? $records->total(), 'filtered' => $records->total(), 'search' => $term,
        ];

        return $records;
    }

    public function metadata(LengthAwarePaginator $records): ?array
    {
        return $this->tables[$records] ?? null;
    }

    private function search(Builder $query, array $fields, string $term): void
    {
        // Identifiers come exclusively from the application whitelist, never the request.
        $query->where(function (Builder $match) use ($fields, $term) {
            foreach ($fields as $field) {
                if (str_contains($field, '.') && ! str_contains($field, '->')) {
                    $relation = substr($field, 0, strrpos($field, '.'));
                    $column = substr($field, strrpos($field, '.') + 1);
                    $match->orWhereHas($relation, fn (Builder $related) => $this->column($related, $column, $term));
                } else {
                    $match->orWhere(fn (Builder $own) => $this->column($own, $field, $term));
                }
            }
        });
    }

    private function column(Builder $query, string $column, string $term): void
    {
        $grammar = $query->getQuery()->getGrammar();
        if ($column === '@name') {
            $names = array_map(fn ($part) => $grammar->wrap($query->qualifyColumn($part)), ['first_name', 'middle_name', 'last_name']);
            $query->whereRaw("CONCAT_WS(' ', ".implode(', ', $names).') LIKE ?', ['%'.$term.'%']);
        } elseif ($column === '@guardian') {
            $names = array_map(fn ($part) => $grammar->wrap($query->qualifyColumn($part)), ['first_name', 'last_name']);
            $query->whereRaw("CONCAT_WS(' ', ".implode(', ', $names).') LIKE ?', ['%'.$term.'%']);
        } else {
            $wrapped = $grammar->wrap($query->qualifyColumn($column));
            $query->whereRaw("REPLACE($wrapped, '_', ' ') LIKE ?", ['%'.str_replace('_', ' ', $term).'%']);
        }
    }
}
