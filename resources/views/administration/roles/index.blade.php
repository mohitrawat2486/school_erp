<x-app-layout title="Roles" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Administration</p><h1 class="mt-2 text-3xl font-bold">Roles</h1><p class="mt-2 text-slate-500">Configure reusable permission sets for your school.</p></div>
@can('create', \App\Models\Role::class)
<a href="{{ route('admin.roles.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add role</span></a>
@endcan
</div><x-admin-feedback />
<x-data-table :records="$roles" title="School roles" icon="shield"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Role</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($roles as $role)
<tr class="border-t border-slate-200"><td class="p-4 font-semibold">{{ $role->name }}</td><td class="p-4"><a href="{{ route('admin.roles.edit',$role) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View permissions</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="2" class="p-6 text-slate-500">No roles found.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
