<?php

namespace App\Http\Controllers;

use App\Enums\SubjectStatus;
use App\Http\Requests\SaveSubjectRequest;
use App\Http\Requests\SubjectIndexRequest;
use App\Models\AcademicYear;
use App\Models\Subject;
use App\Services\SubjectService;
use App\Support\CurrentSchool;
use App\Support\SubjectWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubjectController extends Controller
{
    public function index(SubjectIndexRequest $request): View
    {
        $filters = $request->validated();
        $year = app(CurrentSchool::class)->academicYear();
        $year = $year?->status->value === 'ACTIVE' ? $year : null;
        $totals = ['subjects' => Subject::count(), 'active' => Subject::where('status', 'ACTIVE')->count(), 'inactive' => Subject::where('status', 'INACTIVE')->count(),
            'unassigned' => $year ? Subject::where('status', 'ACTIVE')->whereDoesntHave('classSubjects', fn ($assignments) => $assignments->where('academic_year_id', $year->id)->where('status', 'ACTIVE'))->count() : null];

        return view('subjects.index', ['subjects' => $this->directoryQuery($filters, $year)->paginateTable(20, ['*'], 'page', [])->withQueryString(), 'filters' => $filters, 'year' => $year, 'totals' => $totals, 'catalog' => SubjectWorkspace::catalog($request)]);
    }

    private function directoryQuery(array $filters, ?AcademicYear $year): Builder
    {
        $query = Subject::query();
        if ($year) {
            $query->withCount(['classSubjects as current_classes_count' => fn ($assignments) => $assignments->where('academic_year_id', $year->id)->where('status', 'ACTIVE')]);
        }
        $term = data_get($filters, 'tables.page.search', $filters['q'] ?? '');
        if (is_string($term) && $term !== '') {
            $pattern = '%'.$term.'%';
            $query->where(fn ($search) => $search->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern)->orWhere('type', 'like', $pattern)->orWhere('status', 'like', $pattern));
        }
        foreach (['status', 'type'] as $field) {
            if ($value = $filters[$field] ?? null) {
                $query->where($field, $value);
            }
        }

        return $query->orderBy('name')->orderBy('id');
    }

    public function export(SubjectIndexRequest $request): StreamedResponse
    {
        $year = app(CurrentSchool::class)->academicYear();
        $year = $year?->status->value === 'ACTIVE' ? $year : null;
        $query = $this->directoryQuery($request->validated(), $year);
        $school = app(CurrentSchool::class)->get();

        return response()->streamDownload(function () use ($query, $year, $school) {
            app(CurrentSchool::class)->set($school);
            $stream = fopen('php://output', 'w');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, ['Subject', 'Code', 'Type', 'Classes', 'Academic year', 'Status'], ',', '"', '');
                foreach ($query->lazy(200) as $subject) {
                    $values = [$subject->name, $subject->code, $subject->type->value, $year ? $subject->current_classes_count : '', $year?->name ?? '', $subject->status->value];
                    fputcsv($stream, array_map(fn ($value) => is_string($value) && preg_match('/^\s*[=+\-@]|^[\t\r\n]/', $value) ? "'".$value : $value, $values), ',', '"', '');
                }
            } finally {
                fclose($stream);
                app(CurrentSchool::class)->clear();
            }
        }, 'subjects.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', Subject::class);

        return view('subjects.form', ['subject' => new Subject, 'workspace' => SubjectWorkspace::context($request)]);
    }

    public function store(SaveSubjectRequest $request, SubjectService $service): RedirectResponse
    {
        $subject = $service->save($request->user(), null, $request->validated());

        return redirect()->route('subjects.show', ['subject' => $subject->id, ...SubjectWorkspace::context($request)])->with('status', 'Subject created.');
    }

    public function show(Request $request, Subject $subject): View
    {
        Gate::authorize('view', $subject);

        return view('subjects.show', ['subject' => $subject, 'workspace' => SubjectWorkspace::context($request), 'year' => app(CurrentSchool::class)->academicYear()]);
    }

    public function edit(Request $request, Subject $subject): View
    {
        Gate::authorize('update', $subject);

        return view('subjects.form', ['subject' => $subject, 'workspace' => SubjectWorkspace::context($request)]);
    }

    public function update(SaveSubjectRequest $request, Subject $subject, SubjectService $service): RedirectResponse
    {
        $service->save($request->user(), $subject, $request->validated());

        return redirect()->route('subjects.show', ['subject' => $subject->id, ...SubjectWorkspace::context($request)])->with('status', 'Subject saved.');
    }

    public function activate(Request $request, Subject $subject, SubjectService $service): RedirectResponse
    {
        SubjectWorkspace::context($request);
        $service->setStatus($request->user(), $subject, SubjectStatus::Active);

        return redirect()->route('subjects.show', ['subject' => $subject->id, ...SubjectWorkspace::context($request)])->with('status', 'Subject activated.');
    }

    public function deactivate(Request $request, Subject $subject, SubjectService $service): RedirectResponse
    {
        SubjectWorkspace::context($request);
        $service->setStatus($request->user(), $subject, SubjectStatus::Inactive);

        return redirect()->route('subjects.show', ['subject' => $subject->id, ...SubjectWorkspace::context($request)])->with('status', 'Subject deactivated.');
    }
}
