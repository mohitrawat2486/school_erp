<?php

namespace App\Http\Controllers;

use App\Services\ClassResultDirectory;
use App\Services\ClassResultWorkbook;
use Illuminate\Http\Request;

class ClassResultController extends Controller
{
    public function select(Request $request, ClassResultDirectory $directory)
    {
        $filters = $directory->validate($request->all(), false);
        $steps = ['academic_year_id' => 'Academic year', 'exam_id' => 'Exam', 'class_id' => 'Class', 'section_id' => 'Section'];
        $field = collect(array_keys($steps))->first(fn ($f) => ! ($filters[$f] ?? null));
        if (! $field) {
            return redirect()->route('class-results.index', $filters);
        }
        $names = ['academic_year_id' => 'academic_year_name', 'exam_id' => 'exam_name', 'class_id' => 'class_name', 'section_id' => 'section_name'];
        $search = $request->validate(['search' => 'nullable|string|max:100'])['search'] ?? null;

        return view('class-results.select', ['filters' => $filters, 'field' => $field, 'label' => $steps[$field], 'records' => $directory->choices($filters, $field, $names[$field], $search), 'search' => $search]);
    }

    public function index(Request $request, ClassResultDirectory $directory)
    {
        if (! $request->filled(['academic_year_id', 'exam_id', 'class_id'])) {
            return redirect()->route('class-results.select');
        }
        $filters = $directory->validate($request->all());
        $query = $directory->query($filters);
        $context = $directory->query($filters, true)->firstOrFail();

        return view('class-results.index', ['results' => $query->paginateTable(20, ['*'], 'page', ['student_name'])->withQueryString(), 'filters' => $filters, 'context' => $context, 'sectionName' => ($filters['section_id'] ?? null) ? $directory->query(array_diff_key($filters, ['q' => 1, 'result_status' => 1]))->value('section_name') : null]);
    }

    public function print(Request $request, ClassResultDirectory $directory)
    {
        abort_unless($request->user()->can('result.print'), 403);
        $filters = $directory->validate($request->all());
        $data = $request->validate(['scope' => 'required|in:selected,filtered,class', 'ids' => 'array', 'ids.*' => 'integer']);
        $rows = $directory->output($filters, $data['scope'], $data['ids'] ?? []);

        return response()->view('class-results.print', ['results' => $rows, 'context' => $rows->first()])->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, ClassResultDirectory $directory, ClassResultWorkbook $workbook)
    {
        abort_unless($request->user()->can('result.print'), 403);
        $filters = $directory->validate($request->all());
        $rows = $directory->output($filters, 'filtered');
        $path = $workbook->create($rows);

        return response()->download($path, 'class-results.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }
}
