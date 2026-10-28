<x-app-layout title="Teacher Assignments" :full-width="true"><div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Academics</p><h1 class="mt-2 text-3xl font-bold">Teacher Assignments</h1><p class="mt-2 text-slate-500">Manage teacher responsibilities by academic year.</p></div>
@can('create', \App\Models\TeacherAssignment::class)
<a href="{{ route('assignments.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add assignment</span></a>
@endcan
</div><x-admin-feedback /><form method="GET" action="{{ route('assignments.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar">
@foreach(['academic_year_id','class_id','teacher_id'] as $field)
@if(isset($filters[$field]))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
@endforeach
<div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search assignments</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Teacher, employee number or subject" class="w-full rounded-lg border border-slate-300 p-3"></div>
@foreach(['assignment_type'=>\App\Enums\AssignmentType::cases(),'status'=>\App\Enums\TeacherAssignmentStatus::cases()] as $field=>$options)
<div><label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ $field==='status' ? 'Status' : 'Type' }}</label><select id="{{ $field }}" name="{{ $field }}" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All</option>@foreach($options as $option)<option value="{{ $option->value }}" @selected(($filters[$field] ?? '')===$option->value)>{{ str_replace('_',' ',$option->value) }}</option>@endforeach</select></div>
@endforeach
<button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('assignments.filter') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Choose year / class</a><a href="{{ route('assignments.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$assignments" title="Teaching responsibilities" icon="assignment"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Year</th><th class="p-4">Teacher</th><th class="p-4">Class / scope</th><th class="p-4">Type / subject</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($assignments as $assignment)
<tr class="border-t border-slate-200"><td class="p-4">{{ $assignment->academicYear->name }}</td><td class="p-4 font-semibold">{{ $assignment->teacher->employee->fullName() }}</td><td class="p-4">{{ $assignment->schoolClass->name }} / {{ $assignment->section?->name ?? 'Whole class' }}</td><td class="p-4">{{ str_replace('_',' ',$assignment->assignment_type->value) }}<br>{{ $assignment->subject?->name }}</td><td class="p-4"><x-status-badge :value="$assignment->status->value" /></td><td class="p-4"><a href="{{ route('assignments.show',$assignment) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="6" class="p-8 text-center text-slate-500">No assignments match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
