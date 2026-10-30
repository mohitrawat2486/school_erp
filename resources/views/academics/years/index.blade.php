<x-app-layout title="Academic Years" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Academics</p><h1 class="mt-2 text-3xl font-bold">Academic Years</h1><p class="mt-2 text-slate-500">Manage academic sessions and choose the current year.</p></div>
@can('create', \App\Models\AcademicYear::class)
<a href="{{ route('academic-years.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white hover:bg-[#692018] button-primary"><x-icon name="plus" /><span>Add academic year</span></a>
@endcan
</div>
@if(session('status'))
<div role="status" class="mb-5 rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</div>
@endif
<x-data-table :records="$years" title="Academic sessions" icon="calendar"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="p-4">Academic year</th><th class="p-4">Start date</th><th class="p-4">End date</th><th class="p-4">Status</th><th class="p-4">Current</th><th class="p-4">Actions</th></tr></thead><tbody>
@forelse($years as $year)
<tr class="border-t border-slate-200"><td class="p-4 font-semibold">{{ $year->name }}</td><td class="p-4">{{ $year->start_date->format('d M Y') }}</td><td class="p-4">{{ $year->end_date->format('d M Y') }}</td><td class="p-4"><x-status-badge :value="$year->status->value" /></td><td class="p-4">@if($year->is_current)<span class="rounded-full bg-green-50 px-3 py-1 font-semibold text-green-800">Current</span>@else<span class="text-slate-500">—</span>@endif</td><td class="p-4"><x-action-link :href="route('academic-years.show',$year)" label="View" icon="eye" /></td></tr>
@empty
<tr><td colspan="6" class="p-8 text-center text-slate-500">No academic years yet. An authorized administrator can add your first session.</td></tr>
@endforelse
</tbody></table></x-data-table>
</x-app-layout>
