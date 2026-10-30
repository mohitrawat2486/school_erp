<?php

namespace App\Http\Controllers;

use App\Enums\AcademicYearStatus;
use App\Http\Requests\SaveAcademicYearRequest;
use App\Models\AcademicYear;
use App\Services\AcademicYearService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AcademicYearController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', AcademicYear::class);

        return view('academics.years.index', ['years' => AcademicYear::orderByDesc('start_date')->orderByDesc('id')->paginateTable(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', AcademicYear::class);

        return view('academics.years.form', ['year' => new AcademicYear]);
    }

    public function store(SaveAcademicYearRequest $request, AcademicYearService $service): RedirectResponse
    {
        $year = $service->create($request->user(), $request->validated());

        return redirect()->route('academic-years.show', $year)->with('status', 'Academic year created as a draft.');
    }

    public function show(AcademicYear $academic_year): View
    {
        Gate::authorize('view', $academic_year);

        return view('academics.years.show', ['year' => $academic_year]);
    }

    public function edit(AcademicYear $academic_year): View
    {
        Gate::authorize('update', $academic_year);
        abort_unless($academic_year->status === AcademicYearStatus::Draft, 403, 'Only draft years can be edited.');

        return view('academics.years.form', ['year' => $academic_year]);
    }

    public function update(SaveAcademicYearRequest $request, AcademicYear $academic_year, AcademicYearService $service): RedirectResponse
    {
        $service->update($request->user(), $academic_year, $request->validated());

        return redirect()->route('academic-years.show', $academic_year)->with('status', 'Academic year saved.');
    }

    public function activate(Request $request, AcademicYear $academic_year, AcademicYearService $service): RedirectResponse
    {
        return $this->change($request, $academic_year, $service, 'activate');
    }

    public function close(Request $request, AcademicYear $academic_year, AcademicYearService $service): RedirectResponse
    {
        return $this->change($request, $academic_year, $service, 'close');
    }

    public function archive(Request $request, AcademicYear $academic_year, AcademicYearService $service): RedirectResponse
    {
        return $this->change($request, $academic_year, $service, 'archive');
    }

    public function destroy(Request $request, AcademicYear $academic_year, AcademicYearService $service): RedirectResponse
    {
        $service->transition($request->user(), $academic_year, 'delete');

        return redirect()->route('academic-years.index')->with('status', 'Draft academic year removed.');
    }

    private function change(Request $request, AcademicYear $year, AcademicYearService $service, string $action): RedirectResponse
    {
        $service->transition($request->user(), $year, $action);

        return redirect()->route('academic-years.show', $year)->with('status', 'Academic year updated.');
    }
}
