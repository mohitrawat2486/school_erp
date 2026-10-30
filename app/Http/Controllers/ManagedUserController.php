<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveManagedUserRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAdministrationService;
use App\Support\CurrentSchool;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ManagedUserController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', User::class);

        return view('administration.users.index', ['users' => User::where('school_id', app(CurrentSchool::class)->id())->with('roles')->orderBy('name')->orderBy('id')->paginateTable(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', User::class);

        return $this->form(new User);
    }

    public function edit(User $managed_user): View
    {
        Gate::authorize('view', $managed_user);

        return $this->form($managed_user);
    }

    private function form(User $user): View
    {
        return view('administration.users.form', ['account' => $user, 'roles' => Role::orderBy('name')->get(), 'selectedRoles' => $user->exists ? DB::table('user_roles')->where('school_id', app(CurrentSchool::class)->id())->where('user_id', $user->id)->pluck('role_id')->all() : []]);
    }

    public function store(SaveManagedUserRequest $request, UserAdministrationService $service): RedirectResponse
    {
        $user = $service->save($request->user(), null, $request->validated());

        return redirect()->route('admin.users.edit', $user)->with('status', 'Account created.');
    }

    public function update(SaveManagedUserRequest $request, User $managed_user, UserAdministrationService $service): RedirectResponse
    {
        $service->save($request->user(), $managed_user, $request->validated());
        $fresh = $request->user()->fresh();

        return ($fresh->can('rbac.view') ? redirect()->route('admin.users.edit', $managed_user) : redirect()->route('dashboard'))->with('status', 'Account saved.');
    }
}
