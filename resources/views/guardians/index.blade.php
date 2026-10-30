<x-app-layout title="Guardians" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Guardians</p><h1 class="mt-2 text-3xl font-bold">Guardian List</h1><p class="mt-2 text-slate-500">Manage family contacts shared across student records.</p></div>
@can('create', \App\Models\Guardian::class)
<a href="{{ route('guardians.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add guardian</span></a>
@endcan
</div><x-admin-feedback />
<form method="GET" action="{{ route('guardians.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar"><div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search guardians</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Name, phone or email" class="w-full rounded-lg border border-slate-300 p-3"></div><div><label for="status" class="mb-2 block text-sm font-semibold">Status</label><select id="status" name="status" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All statuses</option>@foreach(\App\Enums\GuardianStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '')===$status->value)>{{ ucfirst(strtolower($status->value)) }}</option>@endforeach</select></div><button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('guardians.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$guardians" title="Guardian directory" icon="users"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Phone</th><th class="p-4">Name</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($guardians as $guardian)
<tr class="border-t border-slate-200"><td class="p-4">{{ $guardian->phone }}</td><td class="p-4 font-semibold">{{ $guardian->fullName() }}</td><td class="p-4"><x-status-badge :value="$guardian->status->value" /></td><td class="p-4"><x-action-link :href="route('guardians.show',$guardian)" label="View" icon="eye" /></td></tr>
@empty
<tr><td colspan="4" class="p-8 text-center text-slate-500">No guardians match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
