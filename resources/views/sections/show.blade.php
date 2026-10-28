<x-app-layout :title="$section->schoolClass->name.' — '.$section->name">
<a href="{{ route('sections.index',['class_id'=>$section->class_id]) }}" class="text-sm font-semibold text-[#8b2f24]">← Sections</a><div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $section->schoolClass->name }} — {{ $section->name }}</h1><p class="mt-2 text-slate-500">Class code: {{ $section->schoolClass->code }}</p></div>
@can('update',$section)
<a href="{{ route('sections.edit',$section) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit section</a>
@endcan
</div><x-admin-feedback />
<section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Section name</dt><dd class="mt-2 font-semibold">{{ $section->name }}</dd></div><div><dt class="text-sm text-slate-500">Capacity</dt><dd class="mt-2 font-semibold">{{ $section->capacity ?? 'Not set' }}</dd></div><div><dt class="text-sm text-slate-500">Section status</dt><dd class="mt-2 font-semibold">{{ $section->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Class status</dt><dd class="mt-2 font-semibold">{{ $section->schoolClass->status->value }}</dd></div></dl>
@can('view',$section->schoolClass)
<a href="{{ route('classes.show',$section->schoolClass) }}" class="mt-6 inline-block font-semibold text-[#8b2f24] underline">View class record</a>
@endcan
</section>
@can('update',$section)
@if($section->status === \App\Enums\SectionStatus::Active)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Deactivate section</summary><p class="mt-3 text-sm text-slate-600">Mark this section inactive while retaining its records.</p><form method="POST" action="{{ route('sections.deactivate',$section) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm deactivation</button></form></details>
@elseif($section->schoolClass->status === \App\Enums\ClassStatus::Active)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Activate section</summary><p class="mt-3 text-sm text-slate-600">Mark this section active again.</p><form method="POST" action="{{ route('sections.activate',$section) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm activation</button></form></details>
@else
<p class="mt-6 text-sm text-slate-600">The class must be active before this section can be activated.</p>
@endif
@endcan
</x-app-layout>
