<x-app-layout :title="$profile->employee->fullName()" :full-width="true">
<a href="{{ route('teachers.index', $workspace['directory'] ?? []) }}" class="text-sm font-semibold text-[#8b2f24]">← Teachers</a><div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $profile->employee->fullName() }}</h1><p class="mt-2 text-slate-500">{{ $profile->employee->employee_number }} · {{ $profile->employee->employee_type->label() }}</p></div>
<div class="table-actions">
@can('update',$profile)
<x-action-link :href="route('teachers.edit',['teacher_profile'=>$profile->id,...$workspace])" label="Edit teacher profile" icon="pencil" />
@endcan
@can('teacher_assignment.view')
<x-action-link :href="route('assignments.index',['teacher_id'=>$profile->id, ...($year ? ['academic_year_id'=>$year->id,'status'=>'ACTIVE'] : []), ...$workspace])" label="View teacher assignments" icon="assignment" :primary="true" />
@endcan
</div>
</div><x-admin-feedback />
<section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Qualifications</dt><dd class="mt-2 whitespace-pre-wrap break-words">{{ $profile->qualification ?: 'Not recorded' }}</dd></div><div><dt class="text-sm text-slate-500">Specialization</dt><dd class="mt-2 break-words font-semibold">{{ $profile->specialization ?: 'Not recorded' }}</dd></div><div><dt class="text-sm text-slate-500">Experience (years)</dt><dd class="mt-2 font-semibold">{{ $profile->experience ?? 'Not recorded' }}</dd></div><div><dt class="text-sm text-slate-500">Teacher profile status</dt><dd class="mt-2 font-semibold">{{ $profile->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Employee status</dt><dd class="mt-2 font-semibold">{{ $profile->employee->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Available for teaching assignments</dt><dd class="mt-2 font-semibold">{{ $profile->isEligible() ? 'Yes' : 'No' }}</dd></div></dl>
@can('view',$profile->employee)
<x-action-link :href="route('employees.show',$profile->employee)" label="View employee record" class="mt-6" />
@endcan
</section>
@can('update',$profile)
@if($profile->status === \App\Enums\TeacherProfileStatus::Active)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Deactivate teacher profile</summary><p class="mt-3 text-sm text-slate-600">Mark this profile inactive while retaining its qualifications and history.</p><form method="POST" action="{{ route('teachers.deactivate',$profile) }}" class="mt-4">@csrf<x-context-fields :values="$workspace" /><button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm deactivation</button></form></details>
@elseif($profile->employee->status === \App\Enums\EmployeeStatus::Active)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Activate teacher profile</summary><p class="mt-3 text-sm text-slate-600">Mark this teacher profile active again.</p><form method="POST" action="{{ route('teachers.activate',$profile) }}" class="mt-4">@csrf<x-context-fields :values="$workspace" /><button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm activation</button></form></details>
@else
<p class="mt-6 text-sm text-slate-600">The employee record must be active before this teacher profile can be activated.</p>
@endif
@endcan

</x-app-layout>
