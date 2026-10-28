<?php

namespace App\Http\Controllers;

use App\Enums\GuardianStatus;
use App\Http\Requests\GuardianIndexRequest;
use App\Http\Requests\SaveGuardianRequest;
use App\Models\Guardian;
use App\Services\GuardianDirectory;
use App\Services\GuardianService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class GuardianController extends Controller
{
    public function index(GuardianIndexRequest $request): View
    {
        return view('guardians.index', ['guardians' => app(GuardianDirectory::class)->search($request->validated()), 'filters' => $request->validated()]);
    }

    public function create(): View
    {
        Gate::authorize('create', Guardian::class);

        return view('guardians.form', ['guardian' => new Guardian]);
    }

    public function store(SaveGuardianRequest $request, GuardianService $service): RedirectResponse
    {
        $record = $service->save($request->user(), null, $request->validated());

        return redirect()->route('guardians.show', $record)->with('status', 'Guardian created.');
    }

    public function show(Guardian $guardian): View
    {
        Gate::authorize('view', $guardian);

        return view('guardians.show', ['guardian' => $guardian, 'links' => $guardian->studentLinks()->with('student')->orderBy('id')->paginate(20)]);
    }

    public function edit(Guardian $guardian): View
    {
        Gate::authorize('update', $guardian);

        return view('guardians.form', compact('guardian'));
    }

    public function update(SaveGuardianRequest $request, Guardian $guardian, GuardianService $service): RedirectResponse
    {
        $service->save($request->user(), $guardian, $request->validated());

        return redirect()->route('guardians.show', $guardian)->with('status', 'Guardian saved.');
    }

    public function activate(Request $request, Guardian $guardian, GuardianService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $guardian, GuardianStatus::Active);

        return back()->with('status', 'Guardian activated.');
    }

    public function deactivate(Request $request, Guardian $guardian, GuardianService $service): RedirectResponse
    {
        $service->setStatus($request->user(), $guardian, GuardianStatus::Inactive);

        return back()->with('status','Guardian deactivated.');
    }
}
