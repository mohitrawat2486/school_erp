<?php

namespace App\Http\Controllers;

use App\Enums\SectionStatus;
use App\Http\Requests\SaveSectionRequest;
use App\Http\Requests\SectionIndexRequest;
use App\Http\Requests\SelectSectionClassRequest;
use App\Models\SchoolClass;
use App\Models\Section;
use App\Services\SectionDirectory;
use App\Services\SectionService;
use App\Support\CurrentSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SectionController extends Controller
{
    public function index(SectionIndexRequest $request, SectionDirectory $directory): View
    {
        return view('sections.index', $directory->data($request->user(), $request->validated()));
    }

    public function export(SectionIndexRequest $request, SectionDirectory $directory): StreamedResponse
    {
        $filters = $request->validated();
        $context = $directory->context($request->user(), $filters);
        $query = $directory->query($filters, $context);
        $school = app(CurrentSchool::class)->get();
        $headers = ['Class', 'Section', 'Section reference', 'Capacity'];
        if ($context['canTeachers']) {
            $headers[] = 'Class teacher';
        }
        $headers[] = 'Status';

        return response()->streamDownload(function () use ($directory, $headers, $query, $context, $school) {
            app(CurrentSchool::class)->set($school);
            $stream = fopen('php://output', 'w');
            try {
                fwrite($stream, "\xEF\xBB\xBF");
                fputcsv($stream, $headers, ',', '"', '');
                foreach ($query->lazy(200) as $section) {
                    fputcsv($stream, $directory->csvRow($section, $context), ',', '"', '');
                }
            } finally {
                fclose($stream);
                app(CurrentSchool::class)->clear();
            }
        }, 'sections.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'private, no-store']);
    }

    private function classPicker(SelectSectionClassRequest $request, bool $creating): View
    {
        $filters = $request->validated();
        $query = SchoolClass::query();
        if ($creating) {
            $query->active();
        }
        if (($q = $filters['q'] ?? null) !== null && $q !== '') {
            $pattern = '%'.$q.'%';
            $query->where(fn ($search) => $search->where('name', 'like', $pattern)->orWhere('code', 'like', $pattern));
        }

        return view('sections.select-class', ['classes' => $query->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginateTable(20)->withQueryString(), 'filters' => $filters, 'creating' => $creating]);
    }

    public function chooseClass(SelectSectionClassRequest $request): View
    {
        return $this->classPicker($request, false);
    }

    public function create(SelectSectionClassRequest $request): View
    {
        $filters = $request->validated();
        if (isset($filters['class_id'])) {
            return view('sections.form', ['section' => new Section, 'schoolClass' => SchoolClass::active()->whereKey($filters['class_id'])->firstOrFail()]);
        }

        return $this->classPicker($request, true);
    }

    public function store(SaveSectionRequest $request, SectionService $service): RedirectResponse
    {
        $section = $service->save($request->user(), null, $request->validated());

        return redirect()->route('sections.show', $section)->with('status', 'Section created.');
    }

    public function show(Section $section): View
    {
        Gate::authorize('view', $section);
        $section->load('schoolClass');

        return view('sections.show', compact('section'));
    }

    public function edit(Section $section): View
    {
        Gate::authorize('update', $section);

        return view('sections.form', ['section' => $section, 'schoolClass' => $section->schoolClass]);
    }

    public function update(SaveSectionRequest $request, Section $section, SectionService $service): RedirectResponse
    {
        $service->save($request->user(), $section, $request->validated());

        return redirect()->route('sections.show', $section)->with('status', 'Section saved.');
    }

    public function activate(Request $request, Section $section, SectionService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $section, SectionStatus::Active);

        return redirect()->route('sections.show', $section)->with('status', 'Section activated.');
    }

    public function deactivate(Request $request, Section $section, SectionService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $section, SectionStatus::Inactive);

        return redirect()->route('sections.show', $section)->with('status', 'Section deactivated.');
    }
}
