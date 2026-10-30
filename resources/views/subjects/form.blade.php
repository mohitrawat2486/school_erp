<x-app-layout :title="$subject->exists ? 'Edit Subject' : 'Add Subject'">
<a href="{{ route('subjects.index',$workspace['catalog']??[]) }}" class="text-sm font-semibold text-[#8b2f24]">← Subjects</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $subject->exists ? 'Edit Subject' : 'Add Subject' }}</h1><x-admin-feedback />
<form method="POST" action="{{ $subject->exists ? route('subjects.update',$subject) : route('subjects.store') }}" class="max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">@csrf<x-context-fields :values="$workspace" />
@if($subject->exists)
@method('PUT')
@endif
<x-employee-field name="code" label="Subject code" :value="$subject->code" maxlength="50" required /><p class="text-sm text-slate-500">Use a unique school code with letters, numbers, dashes or underscores.</p>
<x-employee-field name="name" label="Subject name" :value="$subject->name" maxlength="100" required />
<div><label for="type" class="mb-2 block text-sm font-semibold">Type</label><select id="type" name="type" required class="w-full rounded-lg border border-slate-300 bg-white p-3">@foreach(\App\Enums\SubjectType::cases() as $type)<option value="{{ $type->value }}" @selected(old('type',$subject->type->value)===$type->value)>{{ ucfirst(strtolower($type->value)) }}</option>@endforeach</select></div>
<button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $subject->exists ? 'Save subject' : 'Create subject' }}</button>
</form></x-app-layout>
