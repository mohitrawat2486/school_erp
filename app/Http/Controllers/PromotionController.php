<?php

namespace App\Http\Controllers;

use App\Http\Requests\PromotionIndexRequest;
use App\Http\Requests\SelectPromotionRequest;
use App\Http\Requests\StorePromotionRequest;
use App\Models\PromotionBatch;
use App\Models\StudentEnrollment;
use App\Services\PromotionDirectory;
use App\Services\PromotionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PromotionController extends Controller
{
    public function index(PromotionIndexRequest $request, PromotionDirectory $directory): View
    {
        return view('promotions.index', ['outcomes' => $directory->search($request->validated()), 'filters' => $request->validated(), 'batch' => null]);
    }

    public function create(SelectPromotionRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $source = isset($data['source_enrollment_id']) ? StudentEnrollment::with('student')->findOrFail($data['source_enrollment_id']) : null;
        if (($data['mode'] ?? null) === 'EXIT') {
            return $source ? redirect()->route('students.edit', ['student' => $source->student_id, 'enrollment_year' => $source->academic_year_id])->withFragment('enrollment-heading') : redirect()->route('students.index')->with('status', 'Open a student’s Edit form to record a withdrawal or transfer.');
        }
        $context = $source ? ['source_academic_year_id' => $source->academic_year_id, 'source_class_id' => $source->class_id, 'source_section_id' => $source->section_id] : $data;
        $query = ['progression' => 1, 'progression_year' => $context['source_academic_year_id'] ?? null, 'progression_class' => $context['source_class_id'] ?? null, 'progression_section' => $context['source_section_id'] ?? null];
        if ($source) {
            $query += ['progression_student' => $source->id, 'progression_search' => $source->student->admission_number];
        }

        return redirect()->route('students.index', array_filter($query, fn ($value) => $value !== null));
    }

    public function store(StorePromotionRequest $request, PromotionService $service): RedirectResponse
    {
        $batch = $service->process($request->user(), $request->validated());

        return redirect()->route('promotions.show', $batch)->with('status', 'Operation completed. Review the recorded outcomes below.');
    }

    public function show(PromotionIndexRequest $request, PromotionBatch $promotion, PromotionDirectory $directory): View
    {
        Gate::authorize('view', $promotion);
        $promotion->load(['sourceYear', 'sourceClass', 'sourceSection', 'targetYear', 'targetClass', 'targetSection', 'actor']);

        return view('promotions.index', ['outcomes' => $directory->search($request->validated(), $promotion), 'filters' => $request->validated(), 'batch' => $promotion]);
    }
}
