<x-app-layout title="Subjects" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Academics</p><h1 class="mt-2 text-3xl font-bold">Subjects</h1><p class="mt-2 text-slate-500">Manage your school's subject catalog.</p></div>
@can('create', \App\Models\Subject::class)
<a href="{{ route('subjects.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add subject</span></a>
@endcan
</div><x-admin-feedback />
<form method="GET" action="{{ route('subjects.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar"><div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search subjects</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Name or code" class="w-full rounded-lg border border-slate-300 p-3"></div>
@foreach(['status'=>\App\Enums\SubjectStatus::cases(),'type'=>\App\Enums\SubjectType::cases()] as $field=>$options)
<div><label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ ucfirst($field) }}</label><select id="{{ $field }}" name="{{ $field }}" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All</option>@foreach($options as $option)<option value="{{ $option->value }}" @selected(($filters[$field] ?? '')===$option->value)>{{ ucfirst(strtolower($option->value)) }}</option>@endforeach</select></div>
@endforeach
<button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('subjects.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$subjects" title="Subject catalog" icon="book"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Code</th><th class="p-4">Subject</th><th class="p-4">Type</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($subjects as $subject)
<tr class="border-t border-slate-200"><td class="p-4">{{ $subject->code }}</td><td class="p-4 font-semibold">{{ $subject->name }}</td><td class="p-4">{{ $subject->type->value }}</td><td class="p-4"><x-status-badge :value="$subject->status->value" /></td><td class="p-4"><a href="{{ route('subjects.show',$subject) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="5" class="p-8 text-center text-slate-500">No subjects match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
