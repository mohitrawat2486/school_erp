<?php

namespace App\Http\Controllers;

use App\Enums\GradingSchemeStatus;
use App\Models\GradeRule;
use App\Models\GradingScheme;
use App\Services\GradeResolver;
use App\Services\GradingSchemeService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class GradingSchemeController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('viewAny', GradingScheme::class);
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => ['nullable', Rule::enum(GradingSchemeStatus::class)]]);
        $q = GradingScheme::withCount('rules');
        if ($filters['q'] ?? null) {
            $q->where('name', 'like', '%'.$filters['q'].'%');
        }
        if ($filters['status'] ?? null) {
            $q->where('status', $filters['status']);
        }

        return view('grading.index', ['schemes' => $q->orderBy('name')->paginate(20)->withQueryString(), 'filters' => $filters]);
    }

    public function create()
    {
        Gate::authorize('create', GradingScheme::class);

        return view('grading.form', ['scheme' => new GradingScheme]);
    }

    public function store(Request $request, GradingSchemeService $service)
    {
        $scheme = $service->save($request->user(), null, $request->all());

        return redirect()->route('grading.show', $scheme)->with('status', 'Inactive grading scheme created. Add grade rules before activation.');
    }

    public function show(Request $request, GradingScheme $grading_scheme, GradeResolver $resolver)
    {
        Gate::authorize('view', $grading_scheme);
        $data = $request->validate(['percentage' => 'nullable|numeric|decimal:0,2|between:0,100']);
        $grade = isset($data['percentage']) ? $resolver->resolve($grading_scheme, $data['percentage']) : null;

        return view('grading.show', ['scheme' => $grading_scheme, 'rules' => $grading_scheme->rules()->orderByDesc('min_percentage')->paginate(20)->withQueryString(), 'grade' => $grade, 'percentage' => $data['percentage'] ?? null]);
    }

    public function edit(GradingScheme $grading_scheme)
    {
        Gate::authorize('update', $grading_scheme);

        return view('grading.form', ['scheme' => $grading_scheme]);
    }

    public function update(Request $request, GradingScheme $grading_scheme, GradingSchemeService $service)
    {
        $scheme = $service->save($request->user(), $grading_scheme, $request->all());

        return redirect()->route('grading.show', $scheme)->with('status', 'Grading scheme saved.');
    }

    public function status(Request $request, GradingScheme $grading_scheme, GradingSchemeService $service)
    {
        $service->setStatus($request->user(), $grading_scheme, GradingSchemeStatus::from($request->route('target')), $request->all());

        return redirect()->route('grading.show', $grading_scheme)->with('status', 'Grading scheme status updated.');
    }

    public function ruleCreate(GradingScheme $grading_scheme)
    {
        Gate::authorize('update', $grading_scheme);

        return view('grading.rule-form', ['scheme' => $grading_scheme, 'rule' => new GradeRule]);
    }

    public function ruleEdit(GradingScheme $grading_scheme, GradeRule $grade_rule)
    {
        Gate::authorize('update', $grading_scheme);
        abort_unless((int) $grade_rule->grading_scheme_id === $grading_scheme->id, 404);

        return view('grading.rule-form', ['scheme' => $grading_scheme, 'rule' => $grade_rule]);
    }

    public function ruleStore(Request $request, GradingScheme $grading_scheme, GradingSchemeService $service)
    {
        $service->saveRule($request->user(), $grading_scheme, null, $request->all());

        return redirect()->route('grading.show', $grading_scheme)->with('status', 'Grade rule added.');
    }

    public function ruleUpdate(Request $request, GradingScheme $grading_scheme, GradeRule $grade_rule, GradingSchemeService $service)
    {
        $service->saveRule($request->user(), $grading_scheme, $grade_rule, $request->all());

        return redirect()->route('grading.show', $grading_scheme)->with('status', 'Grade rule saved.');
    }

    public function ruleDelete(Request $request, GradingScheme $grading_scheme, GradeRule $grade_rule, GradingSchemeService $service)
    {
        $service->deleteRule($request->user(), $grading_scheme, $grade_rule, $request->all());

        return redirect()->route('grading.show', $grading_scheme)->with('status', 'Grade rule removed.');
    }
}
