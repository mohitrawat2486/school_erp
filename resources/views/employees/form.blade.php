<x-app-layout :title="$employee->exists ? 'Edit Employee' : 'Add Employee'">
<a href="{{ route('employees.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Employees</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $employee->exists ? 'Edit Employee' : 'Add Employee' }}</h1><x-admin-feedback />
<form action="{{ $employee->exists ? route('employees.update',$employee) : route('employees.store') }}" method="POST" enctype="multipart/form-data" class="max-w-5xl space-y-6">
@csrf
@if($employee->exists)
@method('PUT')
@endif
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Employment details</h2><div class="grid gap-5 sm:grid-cols-2">
<x-employee-field name="employee_number" label="Employee number" :value="$employee->employee_number" maxlength="50" required />
<div><label for="employee_type" class="mb-2 block text-sm font-semibold">Employee type *</label><select id="employee_type" name="employee_type" required class="w-full rounded-lg border border-slate-300 bg-white p-3">@foreach(\App\Enums\EmployeeType::cases() as $type)<option value="{{ $type->value }}" @selected(old('employee_type',$employee->employee_type->value)===$type->value)>{{ $type->label() }}</option>@endforeach</select></div>
<x-employee-field name="joining_date" label="Joining date" type="date" :value="$employee->joining_date?->format('Y-m-d')" required />
<x-employee-field name="designation" label="Designation" :value="$employee->designation" />
<x-employee-field name="department" label="Department" :value="$employee->department" />
</div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Personal details</h2><div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
<x-employee-field name="first_name" label="First name" :value="$employee->first_name" maxlength="100" required />
<x-employee-field name="middle_name" label="Middle name" :value="$employee->middle_name" maxlength="100" />
<x-employee-field name="last_name" label="Last name" :value="$employee->last_name" maxlength="100" />
<div><label for="gender" class="mb-2 block text-sm font-semibold">Gender</label><select id="gender" name="gender" class="w-full rounded-lg border border-slate-300 bg-white p-3"><option value="">Not specified</option>@foreach(\App\Models\Employee::GENDERS as $value=>$label)<option value="{{ $value }}" @selected(old('gender',$employee->gender)===$value)>{{ $label }}</option>@endforeach</select></div>
<x-employee-field name="date_of_birth" label="Date of birth" type="date" :value="$employee->date_of_birth?->format('Y-m-d')" />
</div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Contact information</h2><div class="grid gap-5 sm:grid-cols-2">
<x-employee-field name="email" label="Email address" type="email" :value="$employee->email" />
<x-employee-field name="phone" label="Phone" type="tel" :value="$employee->phone" maxlength="30" />
<x-employee-field name="alternate_phone" label="Alternate phone" type="tel" :value="$employee->alternate_phone" maxlength="30" />
</div><div class="mt-5"><label for="address" class="mb-2 block text-sm font-semibold">Address</label><textarea id="address" name="address" maxlength="2000" rows="3" class="w-full rounded-lg border border-slate-300 p-3">{{ old('address',$employee->address) }}</textarea></div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Employee photo</h2>
@if($employee->photo_path)
<img src="{{ route('employees.photo',$employee) }}" alt="Current employee photo" class="mb-4 h-28 w-28 rounded-lg object-cover">
@endif
<label for="photo" class="mb-2 block text-sm font-semibold">Upload photo</label><input id="photo" name="photo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="photo-help" class="w-full rounded-lg border border-slate-300 p-3 text-sm"><p id="photo-help" class="mt-2 text-sm text-slate-500">PNG, JPEG or WebP; up to 2 MB and 2000 × 2000 pixels. Leave empty to keep the current photo.</p></section>
<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $employee->exists ? 'Save employee' : 'Create employee' }}</button><a href="{{ $employee->exists ? route('employees.show',$employee) : route('employees.index') }}" class="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold">Cancel</a></div>
</form></x-app-layout>
