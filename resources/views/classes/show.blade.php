<x-app-layout :title="$schoolClass->name">
<a href="{{ route('classes.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Classes</a><div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $schoolClass->name }}</h1><p class="mt-2 text-slate-500">{{ $schoolClass->code }}</p></div>
@can('update',$schoolClass)
<a href="{{ route('classes.edit',$schoolClass) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit class</a>
@endcan
</div><x-admin-feedback />
<section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2"><div><dt class="text-sm text-slate-500">Class name</dt><dd class="mt-2 font-semibold">{{ $schoolClass->name }}</dd></div><div><dt class="text-sm text-slate-500">Code</dt><dd class="mt-2 font-semibold">{{ $schoolClass->code }}</dd></div><div><dt class="text-sm text-slate-500">Display order</dt><dd class="mt-2 font-semibold">{{ $schoolClass->sort_order }}</dd></div><div><dt class="text-sm text-slate-500">Status</dt><dd class="mt-2 font-semibold">{{ $schoolClass->status->value }}</dd></div></dl></section>
@can('update',$schoolClass)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">{{ $schoolClass->status === \App\Enums\ClassStatus::Active ? 'Deactivate class' : 'Activate class' }}</summary><p class="mt-3 text-sm text-slate-600">{{ $schoolClass->status === \App\Enums\ClassStatus::Active ? 'Deactivate this class and its active sections. Records will be retained.' : 'Mark this class active again. Reactivate sections separately if needed.' }}</p><form method="POST" action="{{ route($schoolClass->status === \App\Enums\ClassStatus::Active ? 'classes.deactivate' : 'classes.activate',$schoolClass) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">{{ $schoolClass->status === \App\Enums\ClassStatus::Active ? 'Confirm deactivation' : 'Confirm activation' }}</button></form></details>
@endcan
@can('section.view')
<section class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-3 font-semibold">Sections</h2><a href="{{ route('sections.index',['class_id'=>$schoolClass->id]) }}" class="font-semibold text-[#8b2f24] underline">View sections</a>
@can('create',\App\Models\Section::class)
@if($schoolClass->status === \App\Enums\ClassStatus::Active)
<a href="{{ route('sections.create',['class_id'=>$schoolClass->id]) }}" class="ml-4 font-semibold text-[#8b2f24] underline">Add section</a>
@endif
@endcan
</section>
@endcan
</x-app-layout>
