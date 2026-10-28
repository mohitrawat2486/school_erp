<x-app-layout :title="$employee->fullName()">
<a href="{{ route('employees.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Employees</a>
<div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $employee->fullName() }}</h1><p class="mt-2 text-slate-500">{{ $employee->employee_number }} · {{ $employee->employee_type->label() }} · {{ $employee->status->value }}</p></div>
@can('update',$employee)
<a href="{{ route('employees.edit',$employee) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit employee</a>
@endcan
</div><x-admin-feedback />
<section class="rounded-xl border border-slate-200 bg-white p-6">
@if($employee->photo_path)
<img src="{{ route('employees.photo',$employee) }}" alt="Photo of {{ $employee->fullName() }}" class="mb-6 h-32 w-32 rounded-xl object-cover">
@endif
<dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
@foreach(['employee_number'=>'Employee number','first_name'=>'First name','middle_name'=>'Middle name','last_name'=>'Last name','designation'=>'Designation','department'=>'Department','email'=>'Email','phone'=>'Phone','alternate_phone'=>'Alternate phone'] as $field=>$label)
<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-2 break-words font-semibold">{{ $employee->$field ?: '—' }}</dd></div>
@endforeach
<div><dt class="text-sm text-slate-500">Joining date</dt><dd class="mt-2 font-semibold">{{ $employee->joining_date->format('d M Y') }}</dd></div><div><dt class="text-sm text-slate-500">Date of birth</dt><dd class="mt-2 font-semibold">{{ $employee->date_of_birth?->format('d M Y') ?? '—' }}</dd></div><div><dt class="text-sm text-slate-500">Gender</dt><dd class="mt-2 font-semibold">{{ \App\Models\Employee::GENDERS[$employee->gender] ?? 'Not specified' }}</dd></div><div class="sm:col-span-2 lg:col-span-3"><dt class="text-sm text-slate-500">Address</dt><dd class="mt-2 whitespace-pre-wrap break-words">{{ $employee->address ?: '—' }}</dd></div></dl></section>
@can('update',$employee)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">{{ $employee->status === \App\Enums\EmployeeStatus::Active ? 'Deactivate employee' : 'Activate employee' }}</summary><p class="mt-3 text-sm text-slate-600">{{ $employee->status === \App\Enums\EmployeeStatus::Active ? 'Mark this employee inactive and deactivate any linked teacher profile. Records will be retained.' : 'Mark this employee active again. Reactivate their teacher profile separately if needed.' }}</p><form method="POST" action="{{ route($employee->status === \App\Enums\EmployeeStatus::Active ? 'employees.deactivate' : 'employees.activate',$employee) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">{{ $employee->status === \App\Enums\EmployeeStatus::Active ? 'Confirm deactivation' : 'Confirm activation' }}</button></form></details>
@endcan
@can('teacher.view')
<section class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-3 font-semibold">Teacher profile</h2>
@if($employee->teacherProfile)
<a href="{{ route('teachers.show',$employee->teacherProfile) }}" class="font-semibold text-[#8b2f24] underline">View teacher profile</a>
@else
@can('create',\App\Models\TeacherProfile::class)
@if($employee->status === \App\Enums\EmployeeStatus::Active)
<a href="{{ route('teachers.create',['employee_id'=>$employee->id]) }}" class="font-semibold text-[#8b2f24] underline">Create teacher profile</a>
@else
<p class="text-sm text-slate-500">Activate this employee before creating a teacher profile.</p>
@endif
@endcan
@endif
</section>
@endcan
</x-app-layout>
