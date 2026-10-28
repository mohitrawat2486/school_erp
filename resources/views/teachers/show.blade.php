<x-app-layout :title="$profile->employee->fullName()">
<a href="{{ route('teachers.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Teachers</a><div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $profile->employee->fullName() }}</h1><p class="mt-2 text-slate-500">{{ $profile->employee->employee_number }} · {{ $profile->employee->employee_type->label() }}</p></div>
@can('update',$profile)
<a href="{{ route('teachers.edit',$profile) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit teacher profile</a>
@endcan
</div><x-admin-feedback />
<section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Qualifications</dt><dd class="mt-2 whitespace-pre-wrap break-words">{{ $profile->qualification ?: 'Not recorded' }}</dd></div><div><dt class="text-sm text-slate-500">Specialization</dt><dd class="mt-2 break-words font-semibold">{{ $profile->specialization ?: 'Not recorded' }}</dd></div><div><dt class="text-sm text-slate-500">Experience (years)</dt><dd class="mt-2 font-semibold">{{ $profile->experience ?? 'Not recorded' }}</dd></div><div><dt class="text-sm text-slate-500">Teacher profile status</dt><dd class="mt-2 font-semibold">{{ $profile->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Employee status</dt><dd class="mt-2 font-semibold">{{ $profile->employee->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Available for teaching assignments</dt><dd class="mt-2 font-semibold">{{ $profile->isEligible() ? 'Yes' : 'No' }}</dd></div></dl>
@can('view',$profile->employee)
<a href="{{ route('employees.show',$profile->employee) }}" class="mt-6 inline-block font-semibold text-[#8b2f24] underline">View employee record</a>
@endcan
</section>
@can('update',$profile)
@if($profile->status === \App\Enums\TeacherProfileStatus::Active)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Deactivate teacher profile</summary><p class="mt-3 text-sm text-slate-600">Mark this profile inactive while retaining its qualifications and history.</p><form method="POST" action="{{ route('teachers.deactivate',$profile) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm deactivation</button></form></details>
@elseif($profile->employee->status === \App\Enums\EmployeeStatus::Active)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Activate teacher profile</summary><p class="mt-3 text-sm text-slate-600">Mark this teacher profile active again.</p><form method="POST" action="{{ route('teachers.activate',$profile) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm activation</button></form></details>
@else
<p class="mt-6 text-sm text-slate-600">The employee record must be active before this teacher profile can be activated.</p>
@endif
@endcan
@can('teacher_assignment.view')
<a href="{{ route('assignments.index',['teacher_id'=>$profile->id]) }}" class="mt-6 inline-block font-semibold text-[#8b2f24] underline">View teacher assignments</a>
@endcan
</x-app-layout>
