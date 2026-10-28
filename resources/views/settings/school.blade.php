<x-app-layout title="School Details">
<div class="mb-6"><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Settings</p><h1 class="mt-2 text-3xl font-bold">School Details</h1><p class="mt-2 text-slate-500">Manage your school's identity, contact information and branding.</p></div>
@if(session('status'))<div role="status" class="mb-5 rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</div>@endif
@if($errors->any())<div role="alert" class="mb-5 rounded-lg bg-red-50 p-4 text-red-800"><p class="font-semibold">Please review the highlighted fields.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@php($canUpdate = auth()->user()->can('update', $school))
<form action="{{ route('school.update') }}" method="POST" enctype="multipart/form-data" class="space-y-6">@csrf @method('PUT')
<fieldset @disabled(!$canUpdate) class="space-y-6">
<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-semibold">School identity</h2><div class="grid gap-5 sm:grid-cols-2">
@foreach(['code'=>'School code','name'=>'School name','short_name'=>'Short name','affiliation_number'=>'Affiliation number','board'=>'Board'] as $field=>$label)
<x-school-field :name="$field" :label="$label" :value="$school->$field" :required="in_array($field, ['code','name'])" />
@endforeach
<div><label for="timezone" class="mb-2 block text-sm font-semibold">Timezone <span aria-hidden="true">*</span></label><select id="timezone" name="timezone" required class="w-full rounded-lg border border-slate-300 bg-white p-3">@foreach(timezone_identifiers_list() as $timezone)<option value="{{ $timezone }}" @selected(old('timezone',$school->timezone)===$timezone)>{{ $timezone }}</option>@endforeach</select>@error('timezone')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror</div>
</div><p class="mt-4 text-sm text-slate-500">Account access: {{ $school->is_active ? 'Active' : 'Inactive' }}</p></section>
<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-semibold">Contact information</h2><div class="grid gap-5 sm:grid-cols-2">
<x-school-field name="email" label="Email address" type="email" :value="$school->email" />
<x-school-field name="phone" label="Phone" type="tel" :value="$school->phone" />
<x-school-field name="alternate_phone" label="Alternate phone" type="tel" :value="$school->alternate_phone" />
</div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-semibold">School address</h2><div class="grid gap-5 sm:grid-cols-2">
@foreach(['address_line_1'=>'Address line 1','address_line_2'=>'Address line 2','city'=>'City','state'=>'State','country'=>'Country','postal_code'=>'Postal code'] as $field=>$label)<x-school-field :name="$field" :label="$label" :value="$school->$field" />@endforeach
</div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><h2 class="mb-5 text-lg font-semibold">School logo</h2>
@if($school->logo_path)<img src="{{ route('school.logo') }}" alt="Current school logo" class="mb-4 h-28 w-28 rounded-lg border border-slate-200 object-contain p-2">@else<p class="mb-4 text-sm text-slate-500">No school logo uploaded.</p>@endif
<label for="logo" class="mb-2 block text-sm font-semibold">Upload a logo</label><input id="logo" name="logo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="logo-help" class="block w-full rounded-lg border border-slate-300 p-3 text-sm"><p id="logo-help" class="mt-2 text-sm text-slate-500">PNG, JPEG or WebP. Maximum 2 MB and 2000 × 2000 pixels. Leave empty to keep the current logo.</p>@error('logo')<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</section>
</fieldset>
@if($canUpdate)<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white hover:bg-[#692018] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[#8b2f24]">Save school details</button><a href="{{ route('school.show') }}" class="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold">Reset changes</a></div>@else<p class="text-sm text-slate-500">Your account can view school details. Contact your administrator to request changes.</p>@endif
</form></x-app-layout>
