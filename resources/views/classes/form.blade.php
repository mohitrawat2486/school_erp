<x-app-layout :title="$schoolClass->exists ? 'Edit Class' : 'Add Class'">
<a href="{{ route('classes.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Classes</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $schoolClass->exists ? 'Edit Class' : 'Add Class' }}</h1><x-admin-feedback />
<form method="POST" action="{{ $schoolClass->exists ? route('classes.update',$schoolClass) : route('classes.store') }}" class="max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
@csrf
@if($schoolClass->exists)
@method('PUT')
@endif
<x-employee-field name="name" label="Class name" :value="$schoolClass->name" maxlength="100" required />
<p class="text-sm text-slate-500">Use the base class name, such as Nursery or 5. Sections such as A and B are managed separately.</p>
<x-employee-field name="code" label="Class code" :value="$schoolClass->code" maxlength="50" required />
<p class="text-sm text-slate-500">Use letters, numbers, dashes or underscores. Each name and code must be unique within your school.</p>
<div><label for="sort_order" class="mb-2 block text-sm font-semibold">Display order *</label><input id="sort_order" name="sort_order" type="number" inputmode="numeric" step="1" min="0" max="4294967295" value="{{ old('sort_order',$schoolClass->sort_order) }}" required aria-describedby="order-help" class="w-full rounded-lg border border-slate-300 p-3"><p id="order-help" class="mt-2 text-sm text-slate-500">Lower numbers appear first. Classes with the same number are sorted by name.</p></div>
<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $schoolClass->exists ? 'Save class' : 'Create class' }}</button><a href="{{ $schoolClass->exists ? route('classes.show',$schoolClass) : route('classes.index') }}" class="rounded-lg border border-slate-300 px-6 py-3 font-semibold">Cancel</a></div>
</form></x-app-layout>
