<x-app-layout title="Class Subjects" :full-width="true"><div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Academics</p><h1 class="mt-2 text-3xl font-bold">Class Subjects</h1><p class="mt-2 text-slate-500">Allocate subjects and marks settings by academic year and class.</p></div>
@can('create', \App\Models\ClassSubject::class)
<a href="{{ route('class-subjects.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Allocate subject</span></a>
@endcan
</div><x-admin-feedback />
<form method="GET" action="{{ route('class-subjects.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar">
@foreach(['academic_year_id','class_id','subject_id'] as $field)
@if(isset($filters[$field]))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
@endforeach
<div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search subjects</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Subject name or code" class="w-full rounded-lg border border-slate-300 p-3"></div><div><label for="status" class="mb-2 block text-sm font-semibold">Allocation status</label><select id="status" name="status" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All</option>@foreach(\App\Enums\ClassSubjectStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '')===$status->value)>{{ $status->value }}</option>@endforeach</select></div><button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('class-subjects.filter') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Choose year / class</a><a href="{{ route('class-subjects.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a>
@if($filterYear || $filterClass || $filterSubject)
<p class="w-full text-sm font-semibold text-slate-600">{{ collect([$filterYear?->name,$filterClass?->name,$filterSubject?->name])->filter()->join(' · ') }}</p>
@endif
</form>
<x-data-table :records="$allocations" title="Subject allocations" icon="books"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Year</th><th class="p-4">Class</th><th class="p-4">Subject</th><th class="p-4">Maximum</th><th class="p-4">Passing</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($allocations as $allocation)
<tr class="border-t border-slate-200"><td class="p-4">{{ $allocation->academicYear->name }}</td><td class="p-4">{{ $allocation->schoolClass->name }}</td><td class="p-4">{{ $allocation->subject->name }} ({{ $allocation->subject->code }})</td><td class="p-4">{{ $allocation->maximumMarks() ?? 'Not configured' }}</td><td class="p-4">{{ $allocation->passing_marks ?? 'Not configured' }}</td><td class="p-4"><x-status-badge :value="$allocation->status->value" /></td><td class="p-4"><a href="{{ route('class-subjects.show',$allocation) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="7" class="p-8 text-center text-slate-500">No allocations match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
