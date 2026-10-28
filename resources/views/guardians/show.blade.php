<x-app-layout :title="$guardian->fullName()" :full-width="true">
<a href="{{ route('guardians.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Guardians</a>
<div class="list-page-header mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="font-bold">{{ $guardian->fullName() }}</h1><div class="mt-2"><x-status-badge :value="$guardian->status->value" /></div></div>@can('update',$guardian)<a href="{{ route('guardians.edit',$guardian) }}" class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 text-white">Edit guardian</a>@endcan</div><x-admin-feedback />
<section class="mb-6 rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">@foreach(['phone'=>'Phone','alternate_phone'=>'Alternate phone','email'=>'Email','occupation'=>'Occupation','address'=>'Address'] as $field=>$label)<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-2 whitespace-pre-wrap break-words">{{ $guardian->$field ?: '—' }}</dd></div>@endforeach</dl></section>
<x-guardian-contact-table :links="$links" />
@can('update',$guardian)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">{{ $guardian->status->value === 'ACTIVE' ? 'Deactivate guardian' : 'Activate guardian' }}</summary><p class="mt-3 text-sm text-slate-500">Before deactivating, replace or unlink this guardian as primary contact for each student. History is retained.</p><form method="POST" action="{{ route($guardian->status->value === 'ACTIVE' ? 'guardians.deactivate' : 'guardians.activate',$guardian) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm status change</button></form></details>
@endcan
</x-app-layout>
