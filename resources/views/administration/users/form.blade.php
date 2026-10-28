<x-app-layout :title="$account->exists ? 'User Account' : 'Add User'">
<a href="{{ route('admin.users.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Users</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $account->exists ? 'User Account' : 'Add User' }}</h1><x-admin-feedback />
@php($canManage = auth()->user()->can('rbac.manage'))
<form method="POST" action="{{ $account->exists ? route('admin.users.update',$account) : route('admin.users.store') }}" class="max-w-4xl space-y-6">
@csrf
@if($account->exists)
@method('PUT')
@endif
<fieldset @disabled(!$canManage) class="space-y-6">
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Account details</h2><div class="grid gap-5 sm:grid-cols-2"><x-school-field name="name" label="Full name" :value="$account->name" required /><x-school-field name="email" label="Email address" type="email" :value="$account->email" required /></div>
<div class="mt-5"><label for="is_active" class="mb-2 block text-sm font-semibold">Account status</label>
@if($account->id === auth()->id())
<input type="hidden" name="is_active" value="1"><p class="text-sm text-slate-600">Active — you cannot disable your own account.</p>
@else
<select id="is_active" name="is_active" class="w-full rounded-lg border border-slate-300 bg-white p-3"><option value="1" @selected(old('is_active',$account->is_active))>Active</option><option value="0" @selected(!old('is_active',$account->is_active))>Disabled</option></select>
@endif
</div></section>
@if($canManage)
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-2 text-lg font-semibold">{{ $account->exists ? 'Change password' : 'Set password' }}</h2><p class="mb-5 text-sm text-slate-500">At least 12 characters. {{ $account->exists ? 'Leave both fields blank to keep the current password.' : 'Share the initial password with the account holder securely.' }}</p><div class="grid gap-5 sm:grid-cols-2"><div><label for="password" class="mb-2 block text-sm font-semibold">Password</label><input id="password" name="password" type="password" autocomplete="new-password" minlength="12" maxlength="255" @required(!$account->exists) class="w-full rounded-lg border border-slate-300 p-3"></div><div><label for="password_confirmation" class="mb-2 block text-sm font-semibold">Confirm password</label><input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" @required(!$account->exists) class="w-full rounded-lg border border-slate-300 p-3"></div></div></section>
@endif
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-2 text-lg font-semibold">Assigned roles</h2><p class="mb-5 text-sm text-slate-500">This account receives the combined permissions of its selected roles.</p><div class="grid gap-3 sm:grid-cols-2">
@forelse($roles as $role)
<label class="flex items-center gap-3 rounded-lg border border-slate-200 p-3"><input type="checkbox" name="roles[]" value="{{ $role->id }}" @checked(in_array($role->id,(array)old('roles',$selectedRoles)))><span class="text-sm font-semibold">{{ $role->name }}</span></label>
@empty
<p class="text-sm text-slate-500">Create a role before assigning access.</p>
@endforelse
</div></section></fieldset>
@if($canManage)
<button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $account->exists ? 'Save account' : 'Create account' }}</button>
@else
<p class="text-sm text-slate-500">Your account has read-only access to administration.</p>
@endif
</form>
@if($account->exists && $canManage)
<a href="{{ route('admin.users.employee',$account) }}" class="mt-6 inline-block font-semibold text-[#8b2f24] underline">Link employee account</a>
@endif
</x-app-layout>
