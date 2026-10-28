<x-app-layout title="Students" :full-width="true">
<div class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Students</p><h1 class="mt-2 text-3xl font-bold">Student List</h1><p class="mt-2 text-slate-500">Manage admissions and student personal records.</p></div>
@can('create', \App\Models\Student::class)
<a href="{{ route('students.create') }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add student</span></a>
@endcan
</div><x-admin-feedback />
<form method="GET" action="{{ route('students.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar"><div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search students</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Name or admission number" class="w-full rounded-lg border border-slate-300 p-3"></div><div><label for="status" class="mb-2 block text-sm font-semibold">Status</label><select id="status" name="status" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All statuses</option>@foreach(\App\Enums\StudentStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '')===$status->value)>{{ ucfirst(strtolower($status->value)) }}</option>@endforeach</select></div><button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('students.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$students" title="Student directory" icon="users"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Admission number</th><th class="p-4">Name</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($students as $student)
<tr class="border-t border-slate-200"><td class="p-4">{{ $student->admission_number }}</td><td class="p-4 font-semibold">{{ $student->fullName() }}</td><td class="p-4"><x-status-badge :value="$student->status->value" /></td><td class="p-4"><a href="{{ route('students.show',$student) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>View</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="4" class="p-8 text-center text-slate-500">No students match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
