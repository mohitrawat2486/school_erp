<?php

namespace App\Http\Controllers;

use App\Http\Requests\PromotionIndexRequest;
use App\Http\Requests\SelectPromotionRequest;
use App\Http\Requests\StorePromotionRequest;
use App\Models\PromotionBatch;
use App\Services\PromotionDirectory;
use App\Services\PromotionSelection;
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

    public function create(SelectPromotionRequest $request, PromotionSelection $selection): View
    {
        $data = $selection->select($request->validated());

        return view(isset($data['step']) ? 'promotions.select' : 'promotions.form', $data);
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
