<x-app-layout :title="$guardian->exists ? 'Edit Guardian' : 'Add Guardian'">
<a href="{{ route('guardians.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Guardians</a>
<h1 class="mt-4 mb-6 font-bold">{{ $guardian->exists ? 'Edit Guardian' : 'Add Guardian' }}</h1><x-admin-feedback />
<p class="mb-6 text-sm text-slate-500">A guardian can be linked to multiple students. Updating contact details updates the shared contact for all linked students.</p>
<form method="POST" action="{{ $guardian->exists ? route('guardians.update',$guardian) : route('guardians.store') }}" class="max-w-5xl space-y-6">@csrf
@if($guardian->exists)
@method('PUT')
@endif
<section class="rounded-xl border border-slate-200 bg-white p-6"><div class="grid gap-5 sm:grid-cols-2">
<x-form-field name="first_name" label="First name" :value="$guardian->first_name" maxlength="100" required />
<x-form-field name="last_name" label="Last name" :value="$guardian->last_name" maxlength="100" />
<x-form-field name="phone" label="Phone" type="tel" :value="$guardian->phone" maxlength="30" required />
<x-form-field name="alternate_phone" label="Alternate phone" type="tel" :value="$guardian->alternate_phone" maxlength="30" />
<x-form-field name="email" label="Email" type="email" :value="$guardian->email" />
<x-form-field name="occupation" label="Occupation" :value="$guardian->occupation" />
</div><div class="mt-5"><label for="address" class="mb-2 block text-sm font-semibold">Address</label><textarea id="address" name="address" maxlength="2000" rows="3" class="w-full rounded-lg border border-slate-300 p-3">{{ old('address',$guardian->address) }}</textarea></div></section>
<div class="flex flex-wrap gap-3"><button class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">{{ $guardian->exists ? 'Save guardian' : 'Create guardian' }}</button><a href="{{ route('guardians.index') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-3">Cancel</a></div>
</form></x-app-layout>
