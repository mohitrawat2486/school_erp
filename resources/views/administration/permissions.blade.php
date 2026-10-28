<x-app-layout title="Permissions" :full-width="true">
<p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Administration</p><h1 class="mt-2 text-3xl font-bold">Permissions</h1><p class="mt-2 mb-6 text-slate-500">Available application permissions. Assign them through Roles.</p>
<div class="permission-catalog grid gap-5 md:grid-cols-2 lg:grid-cols-3">
@foreach($groups as $group=>$permissions)
<section class="rounded-xl border border-slate-200 bg-white p-5"><h2 class="mb-3 font-semibold capitalize">{{ str_replace('_',' ',$group) }}</h2><ul class="space-y-2 text-sm text-slate-600">@foreach($permissions as $permission)<li>{{ $permission->name }}</li>@endforeach</ul></section>
@endforeach
</div></x-app-layout>
