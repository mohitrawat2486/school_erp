<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveRoleRequest;
use App\Models\Role;
use App\Services\AccessManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class RoleAdministrationController extends Controller
{
    public function index(): View
    {
        Gate::authorize('viewAny', Role::class);

        return view('administration.roles.index', ['roles' => Role::orderBy('name')->paginate(20)]);
    }

    public function create(): View
    {
        Gate::authorize('create', Role::class);

        return $this->form(new Role);
    }

    public function edit(Role $role): View
    {
        Gate::authorize('view', $role);

        return $this->form($role);
    }

    private function form(Role $role): View
    {
        return view('administration.roles.form', ['role' => $role, 'groups' => DB::table('permissions')->orderBy('name')->get()->groupBy(fn ($p) => strstr($p->name, '.', true)), 'selectedPermissions' => $role->exists ? DB::table('role_permissions')->where('role_id', $role->id)->pluck('permission_id')->all() : []]);
    }

    public function store(SaveRoleRequest $request, AccessManager $service): RedirectResponse
    {
        $role = $service->saveRole($request->user(), null, $request->validated('name'), $request->validated('permissions', []));

        return redirect()->route('admin.roles.edit', $role)->with('status', 'Role created.');
    }

    public function update(SaveRoleRequest $request, Role $role, AccessManager $service): RedirectResponse
    {
        $service->saveRole($request->user(), $role, $request->validated('name'), $request->validated('permissions', []));

        return ($request->user()->fresh()->can('rbac.view') ? redirect()->route('admin.roles.edit', $role) : redirect()->route('dashboard'))->with('status', 'Role saved.');
    }

    public function permissions(): View
    {
        return view('administration.permissions', ['groups' => DB::table('permissions')->orderBy('name')->get()->groupBy(fn ($p) => strstr($p->name, '.', true))]);
    }
}
