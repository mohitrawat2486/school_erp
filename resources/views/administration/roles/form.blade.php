<x-app-layout :title="$role->exists ? 'Role Permissions' : 'Add Role'">
<a href="{{ route('admin.roles.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Roles</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $role->exists ? $role->name : 'Add Role' }}</h1><x-admin-feedback />
@php($canManage = auth()->user()->can('rbac.manage'))
<form method="POST" action="{{ $role->exists ? route('admin.roles.update',$role) : route('admin.roles.store') }}" class="max-w-5xl space-y-6">
@csrf
@if($role->exists)
@method('PUT')
@endif
<fieldset @disabled(!$canManage) class="space-y-6">
<section class="rounded-xl border border-slate-200 bg-white p-6"><label for="name" class="mb-2 block text-sm font-semibold">Role name</label><input id="name" name="name" value="{{ old('name',$role->name) }}" required maxlength="100" pattern="[A-Z][A-Z0-9_]*" aria-describedby="role-name-help" class="w-full rounded-lg border border-slate-300 p-3"><p id="role-name-help" class="mt-2 text-sm text-slate-500">Use uppercase letters, numbers and underscores, starting with a letter.</p></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="text-lg font-semibold">Permissions</h2><p class="mt-2 mb-6 text-sm text-slate-500">Grant only the access this role needs. Changes apply to every account assigned this role.</p>
<div class="space-y-6">
@foreach($groups as $group=>$permissions)
<fieldset class="rounded-lg border border-slate-200 p-4"><legend class="px-2 text-sm font-semibold uppercase tracking-wide text-slate-600">{{ str_replace('_',' ',$group) }}</legend><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
@foreach($permissions as $permission)
<label class="flex items-center gap-2 text-sm"><input type="checkbox" name="permissions[]" value="{{ $permission->id }}" @checked(in_array($permission->id,(array)old('permissions',$selectedPermissions)))><span>{{ $permission->name }}</span></label>
@endforeach
</div></fieldset>
@endforeach
</div></section></fieldset>
@if($canManage)
<button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $role->exists ? 'Save role' : 'Create role' }}</button>
@else
<p class="text-sm text-slate-500">Your account has read-only access to administration.</p>
@endif
</form></x-app-layout>
