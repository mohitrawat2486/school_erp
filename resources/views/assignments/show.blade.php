<x-app-layout title="Teacher Assignment"><a href="{{ route('assignments.index',($workspace['list'] ?? ['teacher_id'=>$assignment->teacher_id,'academic_year_id'=>$assignment->academic_year_id]) + (isset($workspace['directory']) ? ['directory'=>$workspace['directory']] : [])) }}" class="text-sm font-semibold text-[#8b2f24]">← Teacher Assignments</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $assignment->teacher->employee->fullName() }}</h1><x-admin-feedback /><section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
@foreach(['Academic year'=>$assignment->academicYear->name,'Class'=>$assignment->schoolClass->name,'Scope'=>$assignment->section?->name ?? 'Whole class — all active sections','Type'=>str_replace('_',' ',$assignment->assignment_type->value),'Subject'=>$assignment->subject?->name ?? 'Class teacher','Assignment status'=>$assignment->status->value,'Currently effective'=>$effective ? 'Yes' : 'No'] as $label=>$value)
<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-2 font-semibold">{{ $value }}</dd></div>
@endforeach
</dl><p class="mt-6 text-sm text-slate-500">Assignment access requires an active year, teacher/employee, class and any selected section and subject allocation. A linked login also needs the relevant permissions.</p></section>
@if(!$assignment->isEditable())
<p class="mt-6 text-sm text-slate-600">This academic year is closed or archived. Assignments are read-only.</p>
@else
@can('update',$assignment)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Change assignment status</summary><p class="mt-3 text-sm text-slate-600">Deactivation retains this scope and history. To change the teacher or scope, deactivate this record and create a new assignment.</p><form method="POST" action="{{ route($assignment->status === \App\Enums\TeacherAssignmentStatus::Active ? 'assignments.deactivate' : 'assignments.activate',$assignment) }}" class="mt-4">@csrf<x-context-fields :values="$workspace" /><button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">{{ $assignment->status === \App\Enums\TeacherAssignmentStatus::Active ? 'Deactivate assignment' : 'Activate assignment' }}</button></form></details>
@endcan
@endif
</x-app-layout>
