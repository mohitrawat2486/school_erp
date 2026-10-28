<x-app-layout title="Sections" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Academics</p><h1 class="mt-2 text-3xl font-bold">Sections</h1><p class="mt-2 text-slate-500">Manage class sections and their capacity.</p></div>
@can('create', \App\Models\Section::class)
<a href="{{ route('sections.create',$selectedClass && $selectedClass->status === \App\Enums\ClassStatus::Active ? ['class_id'=>$selectedClass->id] : []) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add section</span></a>
@endcan
</div><x-admin-feedback />
<form method="GET" action="{{ route('sections.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar">
@if($selectedClass)
<input type="hidden" name="class_id" value="{{ $selectedClass->id }}">
@endif
<div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search sections</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Section name, class name or code" class="w-full rounded-lg border border-slate-300 p-3"></div><div><label for="status" class="mb-2 block text-sm font-semibold">Section status</label><select id="status" name="status" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All statuses</option>@foreach(\App\Enums\SectionStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '')===$status->value)>{{ ucfirst(strtolower($status->value)) }}</option>@endforeach</select></div><button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('sections.choose-class') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">{{ $selectedClass ? 'Change class' : 'Choose class' }}</a><a href="{{ route('sections.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a>
@if($selectedClass)
<p class="w-full text-sm font-semibold text-slate-600">Class: {{ $selectedClass->name }} ({{ $selectedClass->code }})</p>
@endif
</form>
<x-data-table :records="$sections" title="Class sections" icon="sections"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Class</th><th class="p-4">Section</th><th class="p-4">Capacity</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($sections as $section)
<tr class="border-t border-slate-200"><td class="p-4">{{ $section->schoolClass->name }} ({{ $section->schoolClass->code }})</td><td class="p-4 font-semibold">{{ $section->name }}</td><td class="p-4">{{ $section->capacity ?? 'Not set' }}</td><td class="p-4"><x-status-badge :value="$section->status->value" :muted="!$section->isEligible()" /></td><td class="p-4"><a href="{{ route('sections.show',$section) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="5" class="p-8 text-center text-slate-500">No sections match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
