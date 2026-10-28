<x-app-layout title="Users" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Administration</p><h1 class="mt-2 text-3xl font-bold">Users</h1><p class="mt-2 text-slate-500">Manage school accounts and their access.</p></div>
@can('create', \App\Models\User::class)
<a href="{{ route('admin.users.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add user</span></a>
@endcan
</div><x-admin-feedback />
<x-data-table :records="$users" title="User accounts" icon="users"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Name</th><th class="p-4">Email</th><th class="p-4">Roles</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($users as $account)
<tr class="border-t border-slate-200"><td class="p-4 font-semibold">{{ $account->name }}</td><td class="p-4">{{ $account->email }}</td><td class="p-4">{{ $account->roles->pluck('name')->join(', ') ?: 'No roles' }}</td><td class="p-4"><x-status-badge :value="$account->is_active ? 'ACTIVE' : 'DISABLED'" :label="$account->is_active ? 'Active' : 'Disabled'" /></td><td class="p-4"><a href="{{ route('admin.users.edit',$account) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View account</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="5" class="p-6 text-slate-500">No accounts found.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
