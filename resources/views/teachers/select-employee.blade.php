<x-app-layout title="Choose Employee" :full-width="true">
<a href="{{ route('teachers.index', $workspace['directory'] ?? []) }}" class="text-sm font-semibold text-[#8b2f24]">← Teachers</a><h1 class="mt-4 text-3xl font-bold">Choose an employee</h1><p class="mt-2 mb-6 text-slate-500">Select an active employee who does not already have a teacher profile.</p><x-admin-feedback />
<form method="GET" action="{{ route('teachers.create',$workspace) }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar"><x-context-fields :values="$workspace" /><div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search employees</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Employee number or full name" class="w-full rounded-lg border border-slate-300 p-3"></div><button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Search</span></button><a href="{{ route('teachers.create',$workspace) }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$employees" title="Available employees" icon="employees"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Employee number</th><th class="p-4">Name</th><th class="p-4">Employee type</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($employees as $employee)
<tr class="border-t border-slate-200"><td class="p-4">{{ $employee->employee_number }}</td><td class="p-4 font-semibold">{{ $employee->fullName() }}</td><td class="p-4">{{ $employee->employee_type->label() }}</td><td class="p-4"><x-action-link :href="route('teachers.create',['employee_id'=>$employee->id, ...$workspace])" label="Select employee" icon="chevron-right" /></td></tr>
@empty
<tr><td colspan="4" class="p-8 text-center text-slate-500">No active employees without teacher profiles match your search.</td></tr>
@endforelse
</tbody></table></x-data-table>
@can('create', \App\Models\Employee::class)
<a href="{{ route('employees.create') }}" class="mt-6 inline-block font-semibold text-[#8b2f24] underline">Add an employee</a>
@endcan
</x-app-layout>
