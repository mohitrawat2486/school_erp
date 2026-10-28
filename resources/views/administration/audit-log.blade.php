<x-app-layout title="Audit entry" :full-width="true">
    <a href="{{ route('audit.index') }}" class="font-semibold text-[#8b2f24] underline">← Audit logs</a>
    <div class="my-6"><p class="text-sm text-slate-500">Audit entry #{{ $log->id }}</p><h1 class="mt-2 break-words text-3xl font-bold">{{ $log->action }}</h1><p class="mt-2 text-slate-500">Recorded history is read-only. Times use {{ config('app.timezone') }}.</p></div>
    <dl class="mb-6 grid gap-5 rounded-xl border border-slate-200 bg-white p-5 sm:grid-cols-2 xl:grid-cols-4">
        @foreach(['Recorded at'=>$log->created_at->format('d M Y H:i:s'), 'Actor'=>($log->context['actor_name'] ?? 'Name not recorded').' · #'.($log->user_id ?? '—'), 'Module'=>$log->module, 'Record'=>$log->entity_type.' #'.$log->entity_id, 'IP address'=>$log->ip_address ?? 'Not recorded', 'User agent'=>$log->user_agent ?? 'Not recorded'] as $label=>$value)
            <div class="min-w-0"><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-1 break-words font-semibold">{{ $value }}</dd></div>
        @endforeach
        @foreach(collect($log->context ?? [])->except('actor_name') as $label=>$value)
            <div class="min-w-0"><dt class="text-sm text-slate-500">{{ ucwords(str_replace('_', ' ', $label)) }}</dt><dd class="mt-1 break-words font-semibold">{{ $value }}</dd></div>
        @endforeach
    </dl>
    <section class="mb-6 overflow-hidden rounded-xl border border-slate-200 bg-white" aria-label="Changed fields"><h2 class="border-b border-slate-200 p-5 text-lg font-bold">Changed fields</h2>
        <div class="overflow-x-auto" role="region" aria-label="Before and after values" tabindex="0"><table class="w-full min-w-[600px] text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Field</th><th class="p-4">Before</th><th class="p-4">After</th></tr></thead><tbody>
            @forelse($changes as $field=>$change)
                <tr class="border-t border-slate-200"><th class="p-4 align-top">{{ $field }}</th>@foreach(['old','new'] as $side)<td class="max-w-xl p-4 align-top"><pre class="whitespace-pre-wrap break-words">{{ $change[$side.'_present'] ? json_encode($change[$side], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : 'Not present' }}</pre></td>@endforeach</tr>
            @empty<tr><td colspan="3" class="p-5 text-slate-500">No changed fields recorded.</td></tr>@endforelse
        </tbody></table></div>
    </section>
    <details class="rounded-xl border border-slate-200 bg-white p-5"><summary class="cursor-pointer font-semibold">Full recorded values</summary><div class="mt-4 grid min-w-0 gap-5 lg:grid-cols-2">@foreach(['Before'=>$log->old_values,'After'=>$log->new_values] as $label=>$values)<div class="min-w-0"><h2 class="mb-2 font-semibold">{{ $label }}</h2><pre class="max-w-full overflow-x-auto whitespace-pre-wrap break-words rounded-lg bg-slate-50 p-4 text-sm">{{ json_encode($values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre></div>@endforeach</div></details>
</x-app-layout>
