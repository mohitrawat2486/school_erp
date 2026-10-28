<x-app-layout title="Class Subject"><a href="{{ route('class-subjects.index',['academic_year_id'=>$allocation->academic_year_id,'class_id'=>$allocation->class_id]) }}" class="text-sm font-semibold text-[#8b2f24]">← Class Subjects</a><div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $allocation->subject->name }}</h1><p class="mt-2 text-slate-500">{{ $allocation->academicYear->name }} · Class {{ $allocation->schoolClass->name }} · {{ $allocation->subject->code }}</p></div>
@can('update',$allocation)
@if($allocation->isEditable())
<a href="{{ route('class-subjects.edit',$allocation) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit allocation</a>
@endif
@endcan
</div><x-admin-feedback /><section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
@foreach(['theory_max_marks'=>'Theory maximum','practical_max_marks'=>'Practical maximum','passing_marks'=>'Passing marks'] as $field=>$label)
<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-2 font-semibold">{{ $allocation->$field ?? 'Not configured' }}</dd></div>
@endforeach
<div><dt class="text-sm text-slate-500">Total maximum</dt><dd class="mt-2 font-semibold">{{ $allocation->maximumMarks() ?? 'Not configured' }}</dd></div><div><dt class="text-sm text-slate-500">Allocation status</dt><dd class="mt-2 font-semibold">{{ $allocation->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Available for assignments</dt><dd class="mt-2 font-semibold">{{ $allocation->isEligible() ? 'Yes' : 'No' }}</dd></div><div><dt class="text-sm text-slate-500">Academic year status</dt><dd class="mt-2 font-semibold">{{ $allocation->academicYear->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Class status</dt><dd class="mt-2 font-semibold">{{ $allocation->schoolClass->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Subject status</dt><dd class="mt-2 font-semibold">{{ $allocation->subject->status->value }}</dd></div></dl></section>
@if(!$allocation->isEditable())
<p class="mt-6 text-sm text-slate-600">This academic year is closed or archived. Allocation settings are read-only.</p>
@else
@can('update',$allocation)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Change allocation status</summary><p class="mt-3 text-sm text-slate-600">Activation requires an active class and subject. Deactivation preserves the allocation's settings.</p><form method="POST" action="{{ route($allocation->status === \App\Enums\ClassSubjectStatus::Active ? 'class-subjects.deactivate' : 'class-subjects.activate',$allocation) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">{{ $allocation->status === \App\Enums\ClassSubjectStatus::Active ? 'Deactivate allocation' : 'Activate allocation' }}</button></form></details>
@endcan
@endif
</x-app-layout>
